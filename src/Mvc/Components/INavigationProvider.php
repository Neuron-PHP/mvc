<?php

namespace Neuron\Mvc\Components;

/**
 * A component that wants a place in the host application's navigation.
 *
 * MVC defines only the data shape; what renders it is the host's business
 * (the CMS admin layout reads these into its menus). Each item is:
 *
 *   group  string  where it belongs, e.g. 'system', 'content', 'users'
 *   label  string  text shown to the user
 *   route  string  named route; hosts drop items whose route is not registered
 *   icon   string  optional icon class, e.g. 'bi-stack' (Bootstrap Icons)
 *   order  int     optional sort key within the group, lower first (default 100)
 *
 * @package Neuron\Mvc\Components
 */
interface INavigationProvider
{
	/**
	 * @return list<array{group:string,label:string,route:string,icon?:string,order?:int}>
	 */
	public static function navigation(): array;
}
