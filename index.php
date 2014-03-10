<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

include_once './vendor/autoload.php';

$pdo = new \PDO('mysql:host=localhost;dbname=tree;port=3306', 'root', '');
$ns = new NS\NestedSet($pdo, 'tree_test', 'id', 'parent_id', 'tree_id', 'lft', 'rgt');
////$roots = $ns->getRoots();
//echo '<pre>';
//$ns->deleteNode(7);
////echo 'Roots:'.PHP_EOL; 
////var_dump($roots);
////$child = $ns->createChild(1, array('name' => 'Synek 0'), 1);
////var_dump($child);
////var_dump($ns->getNode($child));
//
////echo 'Tree:'.PHP_EOL;
////var_dump($ns->getTree(1));
//
////$ns->createRoot(array('name' => 'root 1'));

