<?php

// Startup and configure Silex application
$app = new GitList\Application($config, __DIR__);

// Mount the controllers
$app->mount('', new GitList\Controller\AuthenticationController());
$app->mount('', new GitList\Controller\MainController());
$app->mount('', new GitList\Controller\BlobController());
$app->mount('', new GitList\Controller\CommitController());
$app->mount('', new GitList\Controller\StatusController());
$app->mount('', new GitList\Controller\CheckoutController());
$app->mount('', new GitList\Controller\PushController());
$app->mount('', new GitList\Controller\TreeController());
$app->mount('', new GitList\Controller\NetworkController());

return $app;
