<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit792ac621de2c2613022cdfb01970bdd2
{
    public static $prefixesPsr0 = array (
        'M' => 
        array (
            'MipsEqLogicTrait' => 
            array (
                0 => __DIR__ . '/..' . '/mips/jeedom-tools/src',
            ),
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixesPsr0 = ComposerStaticInit792ac621de2c2613022cdfb01970bdd2::$prefixesPsr0;

        }, null, ClassLoader::class);
    }
}
