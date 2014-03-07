<?php

namespace GitList;

use Silex\Application as SilexApplication;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Silex\Provider\AuthenticationServiceProvider;
use GitList\Provider\GitServiceProvider;
use GitList\Provider\RepositoryUtilServiceProvider;
use GitList\Provider\ViewUtilServiceProvider;
use GitList\Provider\RoutingUtilServiceProvider;
use DerAlex\Silex\YamlConfigServiceProvider;

/**
 * GitList application.
 */
class Application extends SilexApplication
{
    protected $path;

    /**
     * Constructor initialize services.
     *
     * @param Config $config
     * @param string $root   Base path of the application files (views, cache)
     */
    public function __construct(Config $config, $root = null)
    {
        parent::__construct();
        $app = $this;
        $this->path = realpath($root);

        $this['debug'] = $config->get('app', 'debug');
        $this['filetypes'] = $config->getSection('filetypes');
        $this['cache.archives'] = $this->getCachePath() . 'archives';

        // Register services
        $this->register(new TwigServiceProvider(), array(
            'twig.path'       => $this->getViewPath(),
            'twig.options'    => $config->get('app', 'cache') ?
                                 array('cache' => $this->getCachePath() . 'views') : array(),
        ));

        $repositories = $config->get('git', 'repositories');
        $recurse = $config->get('git', 'recurse');
        $this->register(new GitServiceProvider(), array(
            'git.client'         => $config->get('git', 'client'),
            'git.repos'          => $repositories,
            'ini.file'           => "config.ini",
            'git.hidden'         => $config->get('git', 'hidden') ?
                                    $config->get('git', 'hidden') : array(),
            'git.default_branch' => $config->get('git', 'default_branch') ?
                                    $config->get('git', 'default_branch') : 'master',
            // FALSE means the config wasn't set, and we want backwards compatibility
            'git.recurse'        => ($recurse === FALSE || $recurse === "1") ? TRUE : FALSE,
        ));

        $this->register(new ViewUtilServiceProvider());
        $this->register(new RepositoryUtilServiceProvider());
        $this->register(new UrlGeneratorServiceProvider());
        $this->register(new RoutingUtilServiceProvider());
        $this->register(new SessionServiceProvider());

        $this['twig'] = $this->share($this->extend('twig', function ($twig, $app) {
            $twig->addFilter('htmlentities', new \Twig_Filter_Function('htmlentities'));
            $twig->addFilter('md5', new \Twig_Filter_Function('md5'));

            return $twig;
        }));

        /// Uncomment once to create a new encrypted password and var_dump it to the page
        /// $encoder = new \Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder();
        /// var_dump($encoder->encodePassword('password', ''));

        /// Password protection. Grab user config from users.yml
        $this->register(new YamlConfigServiceProvider(__DIR__ . '/../../users.yml'));
        $users = array();
        foreach($this['config']['users'] as $username => $info)
        {
            if(array_key_exists('credentials', $info))
            {
                $users[$username] = $info['credentials'];
            }
        }
        
        $this['security.firewalls'] = array();
        $this->register(new SecurityServiceProvider(), array(
            'security.firewalls' => array(
                'login' => array(
                    'pattern' => '^/login$'
                ),
                'secured' => array(
                    'pattern' => '^.*$',
                    'form' => array(),
                    'logout' => array(
                        'path' => 'logout',
                    ),
                    'users' => $users
                )
            )
        ));
        $this->boot();

        // Handle errors
        $this->error(function (\Exception $e, $code) use ($app) {
            if ($app['debug']) {
                return;
            }

            return $app['twig']->render('error.twig', array(
                'message' => $e->getMessage(),
            ));
        });
    }

    public function getPath()
    {
        return $this->path . DIRECTORY_SEPARATOR;
    }
    
    public function setPath($path)
    {
        $this->path = $path;
        return $this;
    }

    public function getCachePath()
    {
        return $this->path . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
    }

    public function getViewPath()
    {
        return $this->path . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR;
    }
    
    public function getUser()
    {
        $token = $this['security']->getToken();
        if($token !== null)
        {
            return $token->getUser();
        }    
        return false;
    }
}
