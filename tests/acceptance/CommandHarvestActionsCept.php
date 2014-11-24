<?php

$I = new AcceptanceTester($scenario);
$I->wantTo('execute actions:harvest view command');

$I->runShellCommand("php artisan actions:harvest view");
$I->seeInShellOutput("command fired!");
