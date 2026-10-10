<?php
// Isolated HTTP capture harness: no WordPress, database, or network access.
define('ABSPATH', '/tmp/'); define('HOUR_IN_SECONDS', 3600);
function wp_parse_args($a,$b){return array_merge($b,$a);} function add_action(...$a){} function add_filter(...$a){}
function trailingslashit($s){return rtrim($s,'/').'/';} function wp_json_encode($a){return json_encode($a);}
function add_query_arg($a,$u){return $u.'?'.http_build_query($a);} function is_wp_error($a){return false;}
function wp_remote_request($u,$a){$GLOBALS['captured']=[$u,$a];return [];}
function wp_remote_retrieve_body($r){return '{"success":true}';}
function apply_filters($tag,$v,...$args){return $GLOBALS['fingerprint']??$v;}
require $argv[1];
$c=new Peanut_License_Client(['api_url'=>'https://license.test/api','plugin_slug'=>'peanut-booker','auto_updates'=>false]);
$m=new ReflectionMethod($c,'api_request');
foreach(['license/status'=>'license_key','updates/check'=>'license','updates/info'=>'license'] as $route=>$param){
 $m->invoke($c,$route,[$param=>'SECRET-KEY','plugin'=>'booker'],'GET');[$url,$args]=$GLOBALS['captured'];
 if(str_contains($url,'SECRET-KEY')||($args['headers']['X-Peanut-License-Key']??'')!=='SECRET-KEY')throw new Exception('key transport failed');
}
foreach(['','Existing-Fingerprint',42] as $fingerprint){
 $GLOBALS['fingerprint']=$fingerprint;$m->invoke($c,'license/validate',['license_key'=>'SECRET-KEY','site_url'=>'https://customer.test'],'POST');
 [$url,$args]=$GLOBALS['captured'];$body=json_decode($args['body'],true);
 if(isset($body['license_key']))throw new Exception('key duplicated');
 if($fingerprint==='Existing-Fingerprint'&&($body['hardware_id']??'')!==$fingerprint)throw new Exception('fingerprint lost');
 if($fingerprint!== 'Existing-Fingerprint'&&isset($body['hardware_id']))throw new Exception('unexpected fingerprint');
 if($body['site_url']!=='https://customer.test')throw new Exception('domain lost');
}
echo "PASS: key-free URLs, header authentication, exact opt-in fingerprint, no automatic identity, domain retained\n";
