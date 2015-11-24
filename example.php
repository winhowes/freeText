<?php
require_once('./freeText.php');
$freeText = new FreeText;
$result = $freeText->getCarrier($argv[1]);
echo "Carrier is $result\n";
?>
