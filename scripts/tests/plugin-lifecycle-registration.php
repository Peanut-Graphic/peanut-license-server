<?php
// Include the real inactive plugin before firing init, as WordPress activation does.
define('ABSPATH','/tmp/');
function plugin_dir_path($p){return dirname($p).'/';}function plugin_dir_url($p){return 'https://synthetic.invalid/plugins/peanut-license-server/';}function plugin_basename($p){return 'peanut-license-server/'.basename($p);}
function add_action($tag,$callback,$priority=10,...$args){$GLOBALS['actions'][$tag][$priority][]=$callback;}
function register_activation_hook($file,$callback){$GLOBALS['activation'][realpath($file)]=$callback;}
function register_deactivation_hook($file,$callback){$GLOBALS['deactivation'][realpath($file)]=$callback;}
$main=realpath($argv[1]);require $main;
foreach(['activation','deactivation'] as $hook){if(!is_callable($GLOBALS[$hook][$main]??null))throw new RuntimeException($hook.' hook missing before init');}
if(!in_array('peanut_license_server',$GLOBALS['actions']['init'][0]??[],true))throw new RuntimeException('normal init@0 bootstrap lost');
function wp_next_scheduled($hook){return $GLOBALS['events'][$hook][0]??false;}
function wp_unschedule_event($timestamp,$hook){$GLOBALS['events'][$hook]=array_values(array_filter($GLOBALS['events'][$hook],static fn($v)=>$v!==$timestamp));}
function wp_clear_scheduled_hook($hook){$GLOBALS['events'][$hook]=[];}
require dirname($main).'/includes/class-ml-abuse-detector.php';
$GLOBALS['events']=['peanut_ml_train_abuse_model'=>[100,200],'unrelated_hook'=>[300]];
Peanut_ML_Abuse_Detector::unschedule_training();
if($GLOBALS['events']['peanut_ml_train_abuse_model']!==[]||$GLOBALS['events']['unrelated_hook']!==[300])throw new RuntimeException('training cleanup failed or affected an unrelated hook');
echo 'PASS: lifecycle callbacks available on inactive-plugin include; normal initialization remains deferred to init@0'.PHP_EOL;
