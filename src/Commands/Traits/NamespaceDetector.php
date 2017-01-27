<?php

namespace EloquentFilter\Commands\Traits;

if (trait_exists('Illuminate\Console\DetectsApplicationNamespace')) {
    trait NamespaceDetector
    {
        use \Illuminate\Console\DetectsApplicationNamespace;
    }
}
if (trait_exists('Illuminate\Console\AppNamespaceDetectorTrait')) {
    trait NamespaceDetector
    {
        use \Illuminate\Console\AppNamespaceDetectorTrait;
    }
}
