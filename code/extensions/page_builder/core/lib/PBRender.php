<?php

namespace AbanteCart;

use ADispatcher;
use ADocument;
use ARouter;
use DOMDocument;
use DOMXPath;

/**
 *
 */
class PBRender
{
    /** @var \Registry|null */
    protected $registry;
    /** @var */
    protected $output = '';
    /**
     * @var string Route of main Content Area
     */
    protected $mainRoute = '';
    protected $title = '';
    protected $docStyles = [];
    protected $docJs = [];
    protected $templateData = [];
    protected $customData = [];
    public $componentTypes = [
        'abantecart-generic-block',
        'abantecart-static-block',
        'abantecart-listing-block',
        'abantecart-main-content-area',
    ];

    public function __construct($mainRoute = '')
    {
        $this->registry = \Registry::getInstance();
        if (!$this->registry) {
            throw new \AException(AC_ERR_LOAD, 'Registry instance not found!');
        }
        $this->registry->get('extensions')->hk_InitData($this, __FUNCTION__);
        $this->mainRoute = $mainRoute;
    }

    public function setTitle(string $title = '')
    {
        $this->title = $title;
    }

    public function setTemplate($templateData = [])
    {
        $this->templateData = $templateData;
    }

    /** not implemented yet */
    public function batchAssign($data = [])
    {
        $this->customData += $data;
    }

    public function render()
    {
        $registry = \Registry::getInstance();
        $baseHtmlFile = DIR_PB_TEMPLATES
            .$registry->get('config')->get('config_storefront_template')
            .'/base.html';
        if (!is_file($baseHtmlFile)) {
            copy(DIR_EXT.'page_builder/base.html', $baseHtmlFile);
        }
        $this->output = file_get_contents($baseHtmlFile);

        $this->output = str_replace(
            '{{lang}}',
            $this->registry->get('language')->getLanguageCode(),
            $this->output
        );
        $this->output = str_replace(
            '<!--  {{body}}-->',
            $this->templateData['gjs-html'],
            $this->output
        );
        $this->output = str_replace(
            '<style></style>',
            '<style>'.$this->templateData['gjs-css'].'</style>',
            $this->output
        );
        $this->output = str_replace(
            '{{baseUrl}}',
            HTTPS_SERVER,
            $this->output
        );

        $componentInfo = json_decode($this->templateData['gjs-components'], JSON_PRETTY_PRINT);
        $doc = new DOMDocument();
        $doc->loadHTML($this->output);
        $xpath = new DOMXpath($doc);
        //paste markers into html for replacement with results
        $this->prepareOutput($doc, $xpath, $componentInfo);

        $this->output = $doc->saveHTML();

        //replacing of markers with results
        $this->processComponents($componentInfo);

        //paste cumulative styles and js of blocks
        if ($this->docStyles) {
            $cssTags = '';
            foreach ($this->docStyles as $style) {
                $cssTags .= '<link rel="'.$style['rel'].'" type="text/css" href="'.$style['href'].'" media="'
                    .$style['media'].'" />'."\n";
            }
            $this->output = str_replace(
                '<!--  {{blocks_css}}-->',
                $cssTags,
                $this->output
            );
        }
        if ($this->docJs) {
            $jsTags = '';
            foreach ($this->docJs as $jsSrc) {
                $jsTags .= '<script type="text/javascript" src="'.$jsSrc.'" defer></script>'."\n";
            }
            $this->output = str_replace(
                '<!--  {{blocks_js}}-->',
                $jsTags,
                $this->output
            );
        }
        return $this->output;
    }

    /**
     * @param DOMDocument $doc
     * @param array $renderComponents
     */
    protected function prepareOutput(&$doc, &$xpath, $renderComponents)
    {
        foreach ($renderComponents as $cmp) {
            if (in_array($cmp['type'], $this->componentTypes)) {
                $container = $xpath->query("//*[@id='".$cmp['attributes']['id']."']")->item(0);
                if (!$container) {
                    continue;
                }
                $container->nodeValue = 'content'.$cmp['attributes']['id'];
            }
            if ($cmp['components']) {
                $this->prepareOutput($doc, $xpath, $cmp['components']);
            }
        }
    }

    /**
     * @param array $renderComponents
     */
    protected function processComponents($renderComponents)
    {
        $router = new ARouter($this->registry);
        $router->resetRt();
        foreach ($renderComponents as $cmp) {
            $route = $cmp['route'];
            //check route on existing. If not - take real from request
            if (is_int(strpos($cmp['route'], 'pages/'))) {
                $router->resetRt($route);
                if (!$router->detectController('pages')) {
                    $route = $this->mainRoute;
                }
            }

            if (in_array($cmp['type'], $this->componentTypes) && $route) {
                $args = [
                    'instance_id' => 0,
                    'custom_block_id' => $cmp['custom_block_id'],
                ];
                if ($cmp['type'] == 'abantecart-main-content-area') {
                    if ($cmp['route'] == 'generic') {
                        $Router = new ARouter($this->registry);
                        $Router->resetRt($this->registry->get('request')->get['rt']);
                        $Router->detectController('pages');
                        $route = $cmp['route'] = $Router
                            ? $Router->getController()
                            : 'pages/extension/generic';
                    }
                    if (!$cmp['params'] && $cmp['route'] == 'pages/product/product') {
                        //in case when layout is for default product page - take a random product id
                        $sql = "SELECT product_id 
                                FROM ".$this->registry->get('db')->table('products')." 
                                WHERE date_available <= NOW() AND status=1
                                ORDER BY rand() 
                                LIMIT 1";
                        $res = $this->registry->get('db')->query($sql);
                        $this->registry->get('request')->get['product_id'] = $res->row['product_id'];
                    }
                }
                try {
                    $dis = new ADispatcher($route, $args);
                    $this->registry->set('PBuilder_interception', $dis->getClass());
                    $this->registry->set(
                        'PBuilder_block_template',
                        $cmp['attributes']['data-gjs-template'] ? : $cmp['attributes']['blockTemplate']
                    );
                    $result = $dis->dispatchGetOutput();
                    $this->registry->set('PBuilder_interception', false);
                    /** @var ADocument $doc */
                    $PBRunData = $this->registry->get('PBRunData');
                    $doc = $PBRunData ? $PBRunData['document'] : null;
                    //change Title of Page. take it from main content controller
                    if ($cmp['type'] == 'abantecart-main-content-area') {
                        $title = $doc ? $doc->getTitle() : '';
                        if (!$title) {
                            $this->registry->get('log')->write('DEBUG: '.__CLASS__.' Unknown title for page '.$route);
                        }
                        $this->output = str_replace(
                            '<title></title>',
                            '<title>'.$title.'</title>',
                            $this->output
                        );
                    }

                    $this->registry->set('PBuilder_block_template', '');
                    if (!$result) {
                        $result = '';
                    } //check if block have won scripts and styles
                    elseif ($doc) {
                        $blockStyles = (array) $doc->getStyles();
                        if ($blockStyles) {
                            $this->docStyles += $blockStyles;
                        }
                        $blockJs = (array) $doc->getScripts() + (array) $doc->getScriptsBottom();
                        if ($blockJs) {
                            $this->docJs += $blockJs;
                        }
                    }
                    $this->output = str_replace('content'.$cmp['attributes']['id'], $result, $this->output);
                } catch (\Exception $e) {
                    \Registry::getInstance()->get('log')->write($e->getMessage()."\n".$e->getTraceAsString());
                    exit($e->getMessage());
                }
            }

            if ($cmp['components']) {
                $this->processComponents($cmp['components']);
            }
        }
    }
}