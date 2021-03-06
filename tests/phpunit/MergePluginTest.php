<?php
/**
 * This file is part of the Composer Merge plugin.
 *
 * Copyright (C) 2014 Bryan Davis, Wikimedia Foundation, and contributors
 *
 * This software may be modified and distributed under the terms of the MIT
 * license. See the LICENSE file for details.
 */

namespace Wikimedia\Composer;

use Composer\Composer;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use Prophecy\Argument;

/**
 * @covers Wikimedia\Composer\MergePlugin
 */
class MergePluginTest extends \Prophecy\PhpUnit\ProphecyTestCase
{

    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var MergePlugin
     */
    protected $fixture;

    protected function setUp()
    {
        parent::setUp();
        $this->composer = $this->prophesize('Composer\Composer');
        $this->io = $this->prophesize('Composer\IO\IOInterface');

        $this->fixture = new MergePlugin();
        $this->fixture->activate(
            $this->composer->reveal(),
            $this->io->reveal()
        );
    }

    /**
     * Given a root package with no requires
     *   and a composer.local.json with one require
     * When the plugin is run
     * Then the root package should inherit the require
     *   and no modifications should be made by the pre-dependency hook.
     */
    public function testOneMergeNoConflicts()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(1, count($requires));
                $that->assertArrayHasKey('monolog/monolog', $requires);
            }
        );

        $root->setDevRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(0, count($requires));
            }
        );

        $root->getRepositories()->shouldNotBeCalled();
        $root->setRepositories()->shouldNotBeCalled();

        $root->getSuggests()->shouldNotBeCalled();
        $root->setSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * Given a root package with requires
     *   and a composer.local.json with requires
     *   and the same package is listed in multiple files
     * When the plugin is run
     * Then the root package should inherit the non-conflicting requires
     *   and extra installs should be proposed by the pre-dependency hook.
     */
    public function testOneMergeWithConflicts()
    {
        $that = $this;
        $dir = $this->fixtureDir(__FUNCTION__);

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey(
                    'wikimedia/composer-merge-plugin',
                    $requires
                );
                $that->assertArrayHasKey('monolog/monolog', $requires);
            }
        );

        $root->setDevRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey('foo', $requires);
                $that->assertArrayHasKey('xyzzy', $requires);
            }
        );

        $root->getRepositories()->shouldNotBeCalled();
        $root->setRepositories()->shouldNotBeCalled();

        $root->getSuggests()->shouldNotBeCalled();
        $root->setSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(1, count($extraInstalls));
        $this->assertEquals('monolog/monolog', $extraInstalls[0][0]);
    }

    /**
     * @param RootPackage $package
     * @param string $directory Working directory for composer run
     * @return array Constrains added by MergePlugin::onDependencySolve
     */
    protected function triggerPlugin($package, $directory)
    {
        chdir($directory);
        $this->composer->getPackage()->willReturn($package);

        $event = new CommandEvent(
            ScriptEvents::PRE_INSTALL_CMD,
            $this->composer->reveal(),
            $this->io->reveal(),
            false, //dev mode
            array(),
            array()
        );
        $this->fixture->onInstallOrUpdate($event);

        $requestInstalls = array();
        $request = $this->prophesize('Composer\DependencyResolver\Request');
        $request->install(Argument::any(), Argument::any())->will(
            function ($args) use (&$requestInstalls) {
                $requestInstalls[] = $args;
            }
        );

        $event = new InstallerEvent(
            InstallerEvents::PRE_DEPENDENCIES_SOLVING,
            $this->composer->reveal(),
            $this->io->reveal(),
            $this->prophesize('Composer\DependencyResolver\PolicyInterface')->reveal(),
            $this->prophesize('Composer\DependencyResolver\Pool')->reveal(),
            $this->prophesize('Composer\Repository\CompositeRepository')->reveal(),
            $request->reveal(),
            array()
        );

        $this->fixture->onDependencySolve($event);
        return $requestInstalls;
    }

    /**
     * @param string $subdir
     * @return string
     */
    protected function fixtureDir($subdir)
    {
        return __DIR__ . "/fixtures/{$subdir}";
    }

    /**
     * @param string $file
     * @return ObjectProphecy
     */
    protected function rootFromJson($file)
    {
        $json = json_decode(file_get_contents($file), true);
        $data = array_merge(
            array(
                'repositories' => array(),
                'require' => array(),
                'require-dev' => array(),
                'suggest' => array(),
                'extra' => array(),
            ),
            $json
        );

        $root = $this->prophesize('Composer\Package\RootPackage');
        $root->getRequires()->willReturn($data['require'])->shouldBeCalled();
        $root->getDevRequires()->willReturn($data['require-dev'])->shouldBeCalled();
        $root->getRepositories()->willReturn($data['repositories']);
        $root->getSuggests()->willReturn($data['suggest']);
        $root->getExtra()->willReturn($data['extra'])->shouldBeCalled();

        return $root;
    }
}
// vim:sw=4:ts=4:sts=4:et:
