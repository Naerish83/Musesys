#!/usr/bin/env php
<?php
function extract_hooks($file){
    $code = file_get_contents($file);
    $tokens = token_get_all($code);
    $hooks=[];
    for($i=0;$i<count($tokens);$i++){
        if(is_array($tokens[$i]) && $tokens[$i][0]==T_STRING && in_array($tokens[$i][1],['add_action','add_filter'])){
            $kind = $tokens[$i][1]=='add_action'?'action':'filter';
            $i++;
            while($i<count($tokens) && $tokens[$i] != '('){$i++;}
            $i++;
            while($i<count($tokens) && is_array($tokens[$i]) && $tokens[$i][0]==T_WHITESPACE){$i++;}
            if($i>=count($tokens) || !is_array($tokens[$i])) continue;
            $hook_token=$tokens[$i];
            if($hook_token[0]!=T_CONSTANT_ENCAPSED_STRING) continue;
            $hook = trim($hook_token[1], "'\"");
            $arg_index=1; $depth=1; $priority=10;
            for($i++; $i<count($tokens); $i++){
                $t=$tokens[$i];
                if($t=='('){$depth++;}
                elseif($t==')'){$depth--; if($depth==0) break;}
                elseif($t==',' && $depth==1){$arg_index++;}
                elseif($arg_index==3 && $depth==1){
                    if(is_array($t) && $t[0]==T_LNUMBER){$priority=(int)$t[1];}
                }
            }
            $hooks[]=['kind'=>$kind,'hook'=>$hook,'priority'=>$priority];
        }
    }
    return $hooks;
}
$root='musesys-mega';
$map=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach($iterator as $file){
    if(!$file->isFile()) continue;
    if(substr($file->getFilename(),-4)!=='.php') continue;
    $rel=substr($file->getPathname(), strlen($root)+1);
    $module = str_starts_with($rel,'modules/') ? explode('/', $rel)[1] : 'core';
    foreach(extract_hooks($file->getPathname()) as $h){
        if(str_starts_with($h['hook'],'woocommerce_checkout')){
            $key=$h['kind'].':'.$h['hook'].':'.$h['priority'];
            if(!isset($map[$key])) $map[$key]=[];
            if(!in_array($module,$map[$key])) $map[$key][]=$module;
        }
    }
}
$collisions=false;
foreach($map as $key=>$modules){
    if(count($modules)>1){
        echo "Collision $key ".implode(',', $modules)."\n";
        $collisions=true;
    }
}
if($collisions){exit(1);} else {echo "No checkout collisions\n";}
?>
