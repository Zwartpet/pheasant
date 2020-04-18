<?php

namespace Pheasant\Tests;

use Pheasant;
use Pheasant\DomainObject;
use Pheasant\Events;
use Pheasant\Tests\Examples\EventTestObject;
use Pheasant\Types;

class EventsTest extends \Pheasant\Tests\MysqlTestCase
{
    public function setUp()
    {
        parent::setUp();
        $this->mapper = \Mockery::mock('\Pheasant\Mapper\Mapper');
    }

    public function tearDown()
    {
        parent::tearDown();
        \Mockery::close();
    }

    /**
     * Initialize DomainObject.
     */
    public function initialize($class, $callback = null)
    {
        Pheasant::instance()
            ->register($class, $this->mapper)
            ->initialize($class, $callback)
            ;
    }

    public function testEventsBoundToSchema()
    {
        $this->mapper->shouldReceive('save')->times(1);

        $events = [];
        $callback = function ($e) use (&$events) { $events[] = $e; };

        $this->initialize('Pheasant\DomainObject', function ($builder) use ($callback) {
            $builder->properties([
                'test' => new Types\StringType(),
                ]);
            $builder->events([
                'afterCreate' => $callback,
                ]);
        });

        $do = new DomainObject();
        $do->test = 'blargh';
        $do->save();

        $this->assertEquals($do->test, 'blargh');
        $this->assertEquals($events, ['afterCreate']);
    }

    public function testEventsBoundToObject()
    {
        $this->mapper->shouldReceive('save')->times(2);
        $events = [];

        $this->initialize('Pheasant\DomainObject', function ($builder) {
            $builder->properties([
                'test' => new Types\StringType(),
                ]);
        });

        $do1 = new DomainObject();
        $do2 = new DomainObject();

        $do1->events([
            'afterSave' => function ($e) use (&$events) { $events[] = "do1.$e"; },
            ]);

        $do2->events([
            'afterSave' => function ($e) use (&$events) { $events[] = "do2.$e"; },
            ]);

        $do1->save();
        $do2->save();

        $this->assertEquals($events, ['do1.afterSave', 'do2.afterSave']);
    }

    public function testBuiltInEventMethods()
    {
        $this->mapper->shouldReceive('save')->times(1);

        $this->initialize('Pheasant\Tests\Examples\EventTestObject', function ($builder) {
            $builder->properties([
                'test' => new Types\StringType(),
                ]);
        });

        $do = EventTestObject::create(['test' => 'llamas']);
        $this->assertEquals(['beforeSave', 'afterSave'], $do->events);
    }

    /**
     * Events on objects returned by finder do not fire.
     */
    public function testIssue30()
    {
        $this->mapper->shouldReceive('save')->times(1);

        $this->initialize('Pheasant\Tests\Examples\EventTestObject', function ($builder) {
            $builder->properties([
                'test' => new Types\StringType(),
                ]);
        });

        $do = EventTestObject::fromArray(['test' => 'llamas'], false);
        $do->save();

        $this->assertEquals($do->events, ['beforeSave', 'afterSave']);
    }

    public function testSystemWideInitializeEvent()
    {
        $events = [];
        $ph = $this->pheasant;

        $this->pheasant->events()->register('afterInitialize', function ($e, $schema) use (&$events, $ph) {
            // Issue #49
            // make sure this doesn't trigger recursion
            $mapper = $ph->mapperFor($schema->className());

            $events[] = func_get_args();
        });

        $this->initialize('Pheasant\Tests\Examples\EventTestObject', function ($builder) {
            $builder->properties([
                'test' => new Types\StringType(),
                ]);
        });

        $this->assertCount(1, $events);
        $this->assertEquals('Pheasant\Tests\Examples\EventTestObject', $events[0][1]->className());
        $this->assertEquals('afterInitialize', $events[0][0]);
    }

    public function testEventCorking()
    {
        $fired = [];

        $events = new Events();
        $events->register('*', function () use (&$fired) {
            $fired[] = func_get_args();
        });

        $events->cork();
        $events->trigger('beholdLlamas', new \stdClass());
        $this->assertCount(0, $fired);

        $events->uncork();
        $this->assertCount(1, $fired);
    }

    public function testChainingEventObjects()
    {
        $events1 = new Events();
        $events2 = new Events();

        $fired1 = [];
        $fired2 = [];

        $events1->register('*', function () use (&$fired1) {
            $fired1[] = func_get_args();
        });
        $events2->register('*', function () use (&$fired2) {
            $fired2[] = func_get_args();
        });

        $events1->register('*', $events2);
        $events1->trigger('beholdLlamas', new \stdClass());

        $this->assertCount(1, $fired1);
        $this->assertCount(1, $fired2);
        $this->assertEquals('beholdLlamas', $fired2[0][0]);
    }

    public function testSaveInAfterCreateDoesntLoop()
    {
        $this->mapper->shouldReceive('save')->times(2);

        $this->initialize('Pheasant\Tests\Examples\EventTestObject', function ($builder) {
            $builder->properties([
                'test' => new Types\StringType(),
            ]);
            $builder->events([
                'afterCreate' => function ($event, $obj) {
                    $obj->save();
                },
            ]);
        });

        $do = new EventTestObject();
        $do->test = 'blargh';
        $do->save();

        $this->assertTrue(true);
    }
}
