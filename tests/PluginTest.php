<?php
declare(strict_types=1);

namespace CakephpToStatic\Test;

use CakephpToStatic\CakephpToStaticPlugin;
use CakephpToStatic\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function testBothEntryPointsPreserveCliOnlyFeatures(): void
    {
        foreach ([Plugin::class, CakephpToStaticPlugin::class] as $class) {
            $plugin = new $class(['name' => 'CakephpToStatic']);
            self::assertSame('CakephpToStatic', $plugin->getName());
            self::assertFalse($plugin->isEnabled('routes'));
            self::assertFalse($plugin->isEnabled('middleware'));
        }
    }
}
