<?php
/**
 * Created by PhpStorm.
 * User: Jeff
 * Date: 3/4/14
 * Time: 1:22 PM
 */

namespace GitList\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;


class CheckoutController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];

        $route->get('{repo}/checkout/{branch}', function($repo, $branch) use ($app) {
            
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);
            $repository->checkoutBranch($branch);

            return $app->redirect("/{$repo}/status");
            
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
            ->assert('branch', $app['util.routing']->getBranchRegex())
            ->value('branch', null)
            ->bind('checkout');
        
        
        return $route;
    } 
    
}