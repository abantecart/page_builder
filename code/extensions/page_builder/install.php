<?php


if (! defined ( 'DIR_CORE' )) {
 header ( 'Location: static_pages/' );
}

// add new menu item
$rm = new AResourceManager();
$rm->setType('image');

$language_id = $this->language->getContentLanguageID();
$data = [];
$data['resource_code'] = '<i class="fa fa-object-group"></i>&nbsp;';
$data['name'] = [$language_id => 'Menu Icon Page Builder'];
$data['title'] = [$language_id => ''];
$data['description'] = [$language_id => ''];
$resource_id = $rm->addResource($data);

$menu = new AMenu ("admin");
$menu->insertMenuItem(
    [
        "item_id"         => "page_builder",
        "parent_id"       => "design",
        "item_text"       => "page_builder_name",
        "item_url"        => "design/page_builder",
        "item_icon_rl_id" => $resource_id,
        "item_type"       => "extension",
        "sort_order"      => 2
    ]
);

if(!is_dir(DIR_SYSTEM.'page_builder')){
    if(mkdir(DIR_SYSTEM.'page_builder',0775)){
        $tmpl_id = $this->config->get('config_storefront_template');
        $def = DIR_SYSTEM.'page_builder'.DIRECTORY_SEPARATOR.$tmpl_id;
        mkdir($def,0775);
        copy(
            DIR_EXT.'page_builder'.DIRECTORY_SEPARATOR.'base.html',
            $def.DIRECTORY_SEPARATOR.'base.html'
        );
    }
}


