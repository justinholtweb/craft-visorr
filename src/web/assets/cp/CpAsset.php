<?php

namespace justinholtweb\visorr\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * Styles and behaviour shared by every Visorr control-panel screen, including the panel that
 * appears in the sidebar of element edit screens.
 */
class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [CraftCpAsset::class];

    public $css = ['visorr.css'];

    public $js = ['visorr.js'];
}
