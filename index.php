<?php

/**
 * @file plugins/generic/citeOrbit/index.php
 *
 * Wrapper for the CiteOrbit Reference Validation plugin (OJS 3.3).
 *
 * OJS 3.3 discovers a disk plugin either via this index.php wrapper or via a
 * class named {Dir}{Category}Plugin (i.e. CiteOrbitGenericPlugin). Our class is
 * CiteOrbitPlugin, so this wrapper is what makes the plugin show up in the
 * Plugins grid and get installed into the versions table.
 *
 * @package plugins.generic.citeOrbit
 */

require_once('CiteOrbitPlugin.inc.php');

return new CiteOrbitPlugin();
