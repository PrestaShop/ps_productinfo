<?php
/*
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Productinfo extends Module
{
    protected $html;
    protected $templateFile;

    public function __construct()
    {
        $this->name = 'ps_productinfo';
        $this->author = 'PrestaShop';
        $this->version = '2.0.0';

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Product tooltips', array(), 'Modules.Productinfo.Admin');
        $this->description = $this->trans('Shows information on a product page: how many people are viewing it, the last time it was sold and the last time it was added to a cart.', array(), 'Modules.Productinfo.Admin');
        $this->ps_versions_compliancy = array('min' => '1.7.2.0', 'max' => _PS_VERSION_);

        $this->templateFile = 'module:ps_productinfo/views/templates/hook/ps_productinfo.tpl';
    }

    public function install()
    {
        return (parent::install()
            && Configuration::updateValue('PS_PTOOLTIP_PEOPLE', 1)
            && Configuration::updateValue('PS_PTOOLTIP_DATE_CART', 1)
            && Configuration::updateValue('PS_PTOOLTIP_DATE_ORDER', 1)
            && Configuration::updateValue('PS_PTOOLTIP_DAYS', 3)
            && Configuration::updateValue('PS_PTOOLTIP_LIFETIME', 30)
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayProductButtons')
        );
    }

    public function uninstall()
    {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('PS_PTOOLTIP_PEOPLE') ||
            !Configuration::deleteByName('PS_PTOOLTIP_DATE_CART') ||
            !Configuration::deleteByName('PS_PTOOLTIP_DATE_ORDER') ||
            !Configuration::deleteByName('PS_PTOOLTIP_DAYS') ||
            !Configuration::deleteByName('PS_PTOOLTIP_LIFETIME')) {
            return false;
        }
        return true;
    }

    public function getContent()
    {
        $this->html = '';

        if (Tools::isSubmit('SubmitToolTip')) {
            Configuration::updateValue('PS_PTOOLTIP_PEOPLE', (int) Tools::getValue('PS_PTOOLTIP_PEOPLE'));
            Configuration::updateValue('PS_PTOOLTIP_DATE_CART', (int) Tools::getValue('PS_PTOOLTIP_DATE_CART'));
            Configuration::updateValue('PS_PTOOLTIP_DATE_ORDER', (int) Tools::getValue('PS_PTOOLTIP_DATE_ORDER'));
            Configuration::updateValue('PS_PTOOLTIP_DAYS', ((int) (Tools::getValue('PS_PTOOLTIP_DAYS') < 0 ? 0 : (int)Tools::getValue('PS_PTOOLTIP_DAYS'))));
            Configuration::updateValue('PS_PTOOLTIP_LIFETIME', ((int) (Tools::getValue('PS_PTOOLTIP_LIFETIME') < 0 ? 0 : (int)Tools::getValue('PS_PTOOLTIP_LIFETIME'))));

            $this->html .= $this->displayConfirmation($this->trans('The settings have been updated.', array(), 'Admin.Notifications.Success'));
        }

        $this->html .= $this->renderForm();

        return $this->html;
    }

    public function hookDisplayHeader($params)
    {
        $this->context->controller->addJQueryPlugin('growl');
        $this->context->controller->registerJavascript('modules-ps_productinfo', 'modules/'.$this->name.'/js/ps_productinfo.js', ['position' => 'bottom', 'priority' => 150]);
    }

    public function hookDisplayProductButtons($params)
    {
        $id_product = (is_object($params['product']) ? (int) $params['product']->id : (int) $params['product']['id_product']);

        /* First we try to display the number of people who are currently watching this product page */
        if (Configuration::get('PS_PTOOLTIP_PEOPLE')) {
            $date = strftime('%Y-%m-%d %H:%M:%S', time() - (int) (Configuration::get('PS_PTOOLTIP_LIFETIME') * 60));

            $nb_people = (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
			SELECT COUNT(DISTINCT(id_connections)) nb
			FROM '._DB_PREFIX_.'page p
			LEFT JOIN '._DB_PREFIX_.'connections_page cp ON (p.id_page = cp.id_page)
			WHERE p.id_page_type = 1 AND p.id_object = '.(int)$id_product.' AND cp.time_start > \''.pSQL($date).'\'');

            if ($nb_people > 0) {
                $this->smarty->assign('vars_nb_people', array('%nb_people%' => $nb_people));
            }
        }

        /* Then, we try to display last sale */
        if (Configuration::get('PS_PTOOLTIP_DATE_ORDER')) {
            $date = strftime('%Y-%m-%d', strtotime('-' . (int) Configuration::get('PS_PTOOLTIP_DAYS') . ' day'));

            $date_last_order = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
			SELECT o.date_add
			FROM '._DB_PREFIX_.'order_detail od
			LEFT JOIN '._DB_PREFIX_.'orders o ON (od.id_order = o.id_order)
			WHERE od.product_id = '.(int)$id_product.' AND o.date_add >= \''.pSQL($date).'\'
			ORDER BY o.date_add DESC');

            if ($date_last_order && Validate::isDateFormat($date_last_order) && $date_last_order !== '0000-00-00 00:00:00') {
                $this->smarty->assign('vars_date_last_order', array('%date_last_order%' => Tools::displayDate($date_last_order)));
            } else {
                /* No sale? display last cart add instead */
                if (Configuration::get('PS_PTOOLTIP_DATE_CART')) {
                    $date_last_cart = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
					SELECT cp.date_add
					FROM '._DB_PREFIX_.'cart_product cp
					WHERE cp.id_product = '.(int)$id_product);

                    if ($date_last_cart && Validate::isDateFormat($date_last_cart) && $date_last_cart !== '0000-00-00 00:00:00') {
                        $this->smarty->assign('vars_date_last_cart', array('%date_last_cart%' => Tools::displayDate($date_last_cart)));
                    }
                }
            }
        }

        if (!empty($nb_people) > 0 || !empty($date_last_order) || !empty($date_last_cart)) {
            return $this->fetch($this->templateFile);
        }

        return false;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Settings', array(), 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Number of visitors', array(), 'Modules.Productinfo.Admin'),
                        'desc' => $this->trans('Display the number of visitors who are currently watching this product.', array(), 'Modules.Productinfo.Admin').
                            '<br>'.
                            $this->trans('If you activate the option above, you must activate the first option ("Save page views for each customer") of the "Data mining for statistics" (StatsData) module.', array(), 'Modules.Productinfo.Admin'),
                        'name' => 'PS_PTOOLTIP_PEOPLE',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Period length', array(), 'Modules.Productinfo.Admin'),
                        'desc' => $this->trans('Set the reference period length.', array(), 'Modules.Productinfo.Admin').
                            '<br>'.
                            $this->trans('For instance, if set to 30 minutes, the module will display the number of visitors in the last 30 minutes.', array(), 'Modules.Productinfo.Admin'),
                        'name' => 'PS_PTOOLTIP_LIFETIME',
                        'suffix' => $this->trans('minutes', array(), 'Modules.Productinfo.Admin'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Last order date', array(), 'Modules.Productinfo.Admin'),
                        'desc' => $this->trans('Display the last time the product has been ordered.', array(), 'Modules.Productinfo.Admin'),
                        'name' => 'PS_PTOOLTIP_DATE_ORDER',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->trans('Added to a cart', array(), 'Modules.Productinfo.Admin'),
                        'desc' => $this->trans('If the product has not been ordered yet, display the last time it was added to a cart.', array(), 'Modules.Productinfo.Admin'),
                        'name' => 'PS_PTOOLTIP_DATE_CART',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->trans('Do not display events older than', array(), 'Modules.Productinfo.Admin'),
                        'name' => 'PS_PTOOLTIP_DAYS',
                        'suffix' => $this->trans('days', array(), 'Modules.Productinfo.Admin'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                )
            ),
        );

        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'SubmitToolTip';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'PS_PTOOLTIP_PEOPLE' => Tools::getValue('PS_PTOOLTIP_PEOPLE', Configuration::get('PS_PTOOLTIP_PEOPLE')),
            'PS_PTOOLTIP_LIFETIME' => Tools::getValue('PS_PTOOLTIP_LIFETIME', Configuration::get('PS_PTOOLTIP_LIFETIME')),
            'PS_PTOOLTIP_DATE_ORDER' => Tools::getValue('PS_PTOOLTIP_DATE_ORDER', Configuration::get('PS_PTOOLTIP_DATE_ORDER')),
            'PS_PTOOLTIP_DATE_CART' => Tools::getValue('PS_PTOOLTIP_DATE_CART', Configuration::get('PS_PTOOLTIP_DATE_CART')),
            'PS_PTOOLTIP_DAYS' => Tools::getValue('PS_PTOOLTIP_DAYS', Configuration::get('PS_PTOOLTIP_DAYS')),
        );
    }
}
