<?php

namespace Rector\SOLID\Tests\Rector\ClassMethod\ChangeReadOnlyVariableWithDefaultValueToConstantRector\Fixture;

class MultipleUsage
{
    public function run()
    {
        $replacements = [
            'PHPUnit\Framework\TestCase\Notice' => 'expectNotice',
            'PHPUnit\Framework\TestCase\Deprecated' => 'expectDeprecation',
        ];

        foreach ($replacements as $class => $method) {
        }

        foreach ($replacements as $class => $method) {
        }
    }
}

?>
-----
<?php

namespace Rector\SOLID\Tests\Rector\ClassMethod\ChangeReadOnlyVariableWithDefaultValueToConstantRector\Fixture;

class MultipleUsage
{
    /**
     * @var string[]
     */
    private const REPLACEMENTS = [
        'PHPUnit\Framework\TestCase\Notice' => 'expectNotice',
        'PHPUnit\Framework\TestCase\Deprecated' => 'expectDeprecation',
    ];
    public function run()
    {
        foreach (self::REPLACEMENTS as $class => $method) {
        }
        foreach (self::REPLACEMENTS as $class => $method) {
        }
    }
}

?>
