<?php

namespace justinholtweb\visorr\db;

/**
 * Visorr's table names, in one place, so a rename is a one-line change and a typo is a fatal
 * rather than a silent query against a table that does not exist.
 */
abstract class Table
{
    public const PINS = '{{%visorr_pins}}';
    public const REVISION_SITES = '{{%visorr_revision_sites}}';
    public const PRUNES = '{{%visorr_prunes}}';
}
