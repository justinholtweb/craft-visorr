<?php

namespace justinholtweb\visorr\web\assets\compare;

use craft\web\AssetBundle;
use justinholtweb\visorr\web\assets\cp\CpAsset;

/**
 * The comparison screen: the diff styling, the revision pickers, and the selective-restore
 * selection behaviour.
 */
class CompareAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [CpAsset::class];

    public $css = ['compare.css'];

    public $js = ['compare.js'];
}
