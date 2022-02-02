<?php

class ControllerResponsesExtensionPageBuilder extends AController
{

    public function getControllerOutput()
    {
        $this->load->library('json');
        //use to init controller data
        $this->extensions->hk_InitData($this, __FUNCTION__);

        $route = $this->request->get['route'];
        if ($route) {
            try {
                //if some block requires data from mainContent controller (as example breadcrumbs)
                //run this controller first
                $this->dryRunMainContentController();
                $this->registry->set('PBuilder_interception', true);
                $this->registry->set('PBuilder_block_template', $this->request->get['template']);
                $args = [
                    'instance_id' => 0,
                    'custom_block_id' => $this->request->get['custom_block_id'],
                    'inData' => $this->data
                ];

                if( !$this->request->get['product_id'] && $route == 'pages/product/product') {
                    //in case when layout is for default product page - take a random product id
                    $sql = "SELECT product_id 
                            FROM ". $this->db->table('products')." 
                            WHERE date_available <= NOW() AND status=1
                            ORDER BY rand() 
                            LIMIT 1";
                    $res = $this->db->query($sql);
                    $this->request->get['product_id'] = $res->row['product_id'];
                }

                $dis = new ADispatcher($route, $args);
                //run controller and intercept data
                /** @see ExtensionPageBuilder::__call() */
                $this->data['output'] = $dis->dispatchGetOutput()
                        ? : ($this->registry->get('PBRunData')['data']['empty_render_text']
                        ? : 'Empty Data');
                if ($this->request->get['format'] == 'json') {
                    $this->load->library('json');
                    $this->data['output'] = AJson::encode($this->registry->get('PBRunData')['data']);
                }
                $this->registry->set('PBuilder_interception', false);
                $this->registry->set('PBuilder_block_template', '');
                unset($this->session->data['pbuilder']);
            }catch(Exception $e){
                if($e->getCode() != AC_HOOK_OVERRIDE) {
                    $this->log->write('Page Builder Error: '.$e->getMessage()."\n".$e->getTraceAsString());
                    $this->data['output'] = 'PageBuilder unexpected error. See Error Log for details';
                }
            }
        }
        //use to update controller data
        $this->extensions->hk_UpdateData($this, __FUNCTION__);
        $this->response->setOutput($this->data['output']);
    }

    /**
     * Note: never rename this!
     * @return void
     * @throws AException
     * @throws ReflectionException
     */
    protected function dryRunMainContentController()
    {
        if($this->request->get['route'] == 'blocks/breadcrumbs'){
            $page_id = $this->request->get['page_id'];
            $sql = "SELECT * 
                    FROM ".$this->db->table('pages')." 
                    WHERE page_id = ".(int)$page_id;
            $result = $this->db->query($sql);
            if($result->row) {
                if($result->row['key_param']){
                    $this->request->get[$result->row['key_param']] = $result->row['key_value'];
                }
                $this->registry->set('PBuilder_interception', true);
                //set sign for dry-run of controller to know it inside hooks
                $this->registry->set('PBuilder_dryrun', true);
                $dis = new ADispatcher($result->row['controller'] );
                //run controller and intercept data
                /** @see ExtensionPageBuilder::__call() */
                $dis->dispatchGetOutput();
                $this->data = array_merge($this->data, $this->registry->get('PBRunData')['data']);
                $this->registry->set('PBuilder_interception', false);
                $this->registry->set('PBuilder_dryrun', false);
                $this->registry->set('PBuilder_block_template', '');
                unset($this->session->data['pbuilder']);
            }
        }
    }

}