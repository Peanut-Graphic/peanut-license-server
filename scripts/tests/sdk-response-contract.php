<?php
// Isolated response contract; no network, credentials, or WordPress database.
define('ABSPATH','/tmp/');define('HOUR_IN_SECONDS',3600);
function wp_parse_args($a,$b){return array_merge($b,$a);}function add_action(...$a){}function add_filter(...$a){}
function trailingslashit($s){return rtrim($s,'/').'/';}function wp_json_encode($a){return json_encode($a);}function apply_filters($t,$v,...$a){return $v;}
function is_wp_error($r){return false;}function wp_remote_request($u,$a){return $GLOBALS['fixture'];}
function wp_remote_retrieve_body($r){return $r['body'];}function wp_remote_retrieve_response_code($r){return $r['status'];}function __($s,$d){return $s;}
require $argv[1];$c=new Peanut_License_Client(['api_url'=>'https://synthetic.invalid/api','auto_updates'=>false]);$m=new ReflectionMethod($c,'api_request');if(PHP_VERSION_ID<80100)$m->setAccessible(true);
$cases=[
 ['rest error',400,['code'=>'rest_missing_callback_param','message'=>'Missing parameter'],false,'rest_missing_callback_param'],
 ['http failure with success body',503,['success'=>true,'license'=>['tier'=>'pro']],false,'server_error'],
 ['null JSON',200,null,false,'invalid_response'],
 ['scalar JSON',200,'oops',false,'invalid_response'],
 ['list JSON',200,[1,2],false,'invalid_response'],
 ['missing success',200,['license'=>['tier'=>'pro']],false,'invalid_response'],
 ['nonboolean success',200,['success'=>'true','license'=>[]],false,'invalid_response'],
 ['explicit restriction',400,['success'=>false,'error'=>'license_restricted','message'=>'Denied'],false,'license_restricted'],
 ['success missing license',200,['success'=>true],false,'invalid_response'],
 ['success scalar license',200,['success'=>true,'license'=>'bad'],false,'invalid_response'],
 ['valid license',200,['success'=>true,'license'=>['tier'=>'pro']],true,null],
];
set_error_handler(static function($n,$s){throw new RuntimeException($s);});
foreach($cases as [$label,$status,$data,$expected,$error]){$GLOBALS['fixture']=['status'=>$status,'body'=>json_encode($data)];$r=$m->invoke($c,'license/validate',[],'POST');if(($r['success']??null)!==$expected||($error!==null&&($r['error']??null)!==$error))throw new RuntimeException($label.' contract failed');}
$GLOBALS['fixture']=['status'=>200,'body'=>'{"name":"Synthetic","slug":"test","version":"1.0.0"}'];$r=$m->invoke($c,'updates/info',[],'POST');if($r!==['name'=>'Synthetic','slug'=>'test','version'=>'1.0.0'])throw new RuntimeException('update metadata changed');
echo "PASS: eleven response cases and unchanged update metadata\n";
