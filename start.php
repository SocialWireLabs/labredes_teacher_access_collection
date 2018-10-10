<?php
/*
 * © Copyright by Laboratorio de Redes 2011—2012
 */

function lbr_teachers_ensure_acl(ElggGroup $group) {
    if (isset($group->teachers_acl)) {
        return $group->teachers_acl;
    }

    // Create the acl and add any user that should already be part of it
    if ($group->getSubtype() == 'lbr_subgroup') {
        $name = elgg_echo('lbr_teachers_and_mates_acl_name', array(get_entity($group->container_guid)->name,
                                                                   $group->name));
    } else {
        $name = elgg_echo('lbr_teachers_acl_name', array($group->name));
    }
    $acl_id = create_access_collection($name, $group->getGUID());
    $group->teachers_acl = $acl_id;

    $operators_group = $group;
    if ($group->getSubtype() == 'lbr_subgroup') { // Add every user
        $nmembers = $group->getMembers(array('limit'=>10, 'offset'=>0, 'count'=>true));
        $offset = 0;
        while ($offset < $nmembers) {
            $members = $group->getMembers(array('limit'=>100, 'offset'=>$offset, 'count'=>false));
            $offset += 100;
            foreach ($members as $member) {
                lbr_teachers_add_to_teachers($member, $group);
            }
        }
        $operators_group = get_entity($group->container_guid);
    }
    // Now, add the operators and owner as teachers
    lbr_teachers_add_to_teachers(get_entity($operators_group->owner_guid), $group);
    if (elgg_is_active_plugin('group_tools')) {
        $offset = 0;
        $noperators = elgg_get_entities_from_relationship(array(
        	'types' => 'user',            
            'count' => true,            
            'relationship' => 'group_admin',
            'relationship_guid' => $operators_group->getGUID(),
            'inverse_relationship' => true,
        ));
        while ($offset < $noperators) {
            $operators = elgg_get_entities_from_relationship(array(
        	'types' => 'user',            
            'limit' => 100,
            'offset' => $offset,            
            'relationship' => 'group_admin',
            'relationship_guid' => $operators_group->getGUID(),
            'inverse_relationship' => true,
            ));
            $offset += 100;
            foreach ($operators as $operator) {
                lbr_teachers_add_to_teachers($operator, $group);
            }
        }
    }

    return $group->teachers_acl;
}

function lbr_teachers_add_to_teachers(ElggUser $user, ElggGroup $group) {
    lbr_teachers_ensure_acl($group);

    add_user_to_access_collection($user->getGUID(), $group->teachers_acl);
}

function lbr_teachers_remove_from_teachers(ElggUser $user, ElggGroup $group) {
    lbr_teachers_ensure_acl($group);

    remove_user_from_access_collection($user->getGUID(), $group->teachers_acl);
}

function lbr_teachers_user_leave_event_listener($event, $object_type, $object) {
    $group = $object['group'];
    $user = $object['user'];

    if ($group instanceof ElggGroup and $group->getSubtype() == 'lbr_subgroup') {
        lbr_teachers_remove_from_teachers($user, $group);
    }

    return true;
}

function lbr_teachers_user_join_event_listener($event, $object_type, $object) {
    $group = $object['group'];
    $user = $object['user'];

    if ($group instanceof ElggGroup and $group->getSubtype() == 'lbr_subgroup') {
        lbr_teachers_add_to_teachers($user, $group);
    }

    return true;
}

function lbr_teachers_delete_event_listener($event, $object_type, $object) {
    if (isset($object->teachers_acl)) {
        delete_access_collection($object->teachers_acl);
    }

    return true;
}

function lbr_teachers_operator_add_listener($event, $object_type, $object) {
    $user = get_entity($object->guid_one);
    $group = get_entity($object->guid_two);

    lbr_teachers_add_to_teachers($user, $group);
    // Add also to any subgroup
    $nsubgroups = elgg_get_entities(array(
            'type_subtype_pairs' => array('group' => 'lbr_subgroup'),
            'container_guid' => $group->getGUID(),
            'count' => true,
    ));
    $offset = 0;
    while ($offset < $nsubgroups) {
        $subgroups = elgg_get_entities(array(
            'type_subtype_pairs' => array('group' => 'lbr_subgroup'),
            'container_guid' => $group->getGUID(),
            'count' => false,
            'limit' => 100,
            'offset' => $offset,
        ));
        $offset += 100;
        foreach ($subgroups as $subgroup) {
            lbr_teachers_add_to_teachers($user, $subgroup);
        }
    }
}

function lbr_teachers_operator_remove_listener($event, $object_type, $object) {
    $user = get_entity($object->guid_one);
    $group = get_entity($object->guid_two);

    lbr_teachers_remove_from_teachers($user, $group);
    // Add also to any subgroup
    $nsubgroups = elgg_get_entities(array(
            'type_subtype_pairs' => array('group' => 'lbr_subgroup'),
            'container_guid' => $group->getGUID(),
            'count' => true,
    ));
    $offset = 0;
    while ($offset < $nsubgroups) {
        $subgroups = elgg_get_entities(array(
            'type_subtype_pairs' => array('group' => 'lbr_subgroup'),
            'container_guid' => $group->getGUID(),
            'count' => false,
            'limit' => 100,
            'offset' => $offset,
        ));
        $offset += 100;
        foreach ($subgroups as $subgroup) {
            if (!$subgroup->isMember($user)) { // Only remove it if it is not a member!
                lbr_teachers_remove_from_teachers($user, $subgroup);
            }
        }
    }
}

function lbr_teacher_write_acl_plugin_hook_groups($hook, $entity_type, $returnvalue, $params) {
    if ($entity_type == 'user') {
        $user_id = $params['user_id'];

        /* Groups owned by user */
        $owned = elgg_get_entities(array(
            'limit' => null,
            'types' => 'group',
        	'subtypes' => array(ELGG_ENTITIES_NO_VALUE),
            'owner_guids' => $user_id,        
        ));
        if (!$owned) {
            $owned = array();
        }

        /* Groups operated by user */
        if (elgg_is_active_plugin('group_tools')) {
            $operated = elgg_get_entities_from_relationship(array(
              'types' => 'group',
              'subtypes' => array(ELGG_ENTITIES_NO_VALUE),
              'limit' => null,              
              'relationship' => 'group_admin',
              'relationship_guid' => $user_id,
              'inverse_relationship' => false,
            ));
        } else {
            $operated = false;
        }

        if (!$operated) {
            $operated = array();
        }

        foreach (array_merge($owned, $operated) as $group) {
            lbr_teachers_ensure_acl($group); // Init the group if needed
            $returnvalue[$group->teachers_acl] = elgg_echo('lbr_teachers_acl_name', array($group->name));
            $container_guids[] = $group->getGUID();
        }

        // Add also subgroups operated or owned by them
        if (is_array($container_guids)) {
            $sub = elgg_get_entities(array(
                      'type_subtype_pairs' => array('group' => 'lbr_subgroup'),
                      'limit' => null,                      
                      'container_guids' => $container_guids,
            ));
            if (is_array($sub)) {
                foreach ($sub as $group) {
                    lbr_teachers_ensure_acl($group); // Init the group if needed
                    $returnvalue[$group->teachers_acl] = $name = elgg_echo('lbr_teachers_and_mates_acl_name', array(get_entity($group->container_guid)->name,
                                                                                                                    $group->name));
                    /* Lets also remove normal group acl. Makes no sense at all */
                    unset($returnvalue[$group->group_acl]);
                }
            }
        }
    }

    return $returnvalue;
}

function lbr_teacher_write_acl_plugin_hook_alumni($hook, $entity_type, $returnvalue, $params) {
    if ($entity_type == 'user') {
        $user_id = $params['user_id'];

        $subgroups = elgg_get_entities_from_relationship(array(
            'types' => 'group',                    	        
            'limit' => null,
            'relationship' => 'member',
            'relationship_guid' => $user_id,
        ));
        if (is_array($subgroups)) {
            foreach ($subgroups as $group) {
                lbr_teachers_ensure_acl($group); // Init the group if needed
                if ($group->getSubtype() == 'lbr_subgroup') {
                    $name = elgg_echo('lbr_teachers_and_mates_acl_name', array(get_entity($group->container_guid)->name,
                                                                               $group->name));
                } else {
                    $name = elgg_echo('lbr_teachers_acl_name', array($group->name));
                }
                $returnvalue[$group->teachers_acl] = $name;
            }
        }
    }

    return $returnvalue;
}

function lbr_teachers_init() {
    // Register a handler for delete groups
    elgg_register_event_handler('delete', 'group', 'lbr_teachers_delete_event_listener');

    // Manage access collection as users come and go (for subgroups they must be part of the access collection)
    elgg_register_event_handler('join', 'group','lbr_teachers_user_join_event_listener');
    elgg_register_event_handler('leave', 'group','lbr_teachers_user_leave_event_listener');

    if (elgg_is_active_plugin('group_tools')) {
        elgg_register_event_handler('create', 'group_admin', 'lbr_teachers_operator_add_listener');
        elgg_register_event_handler('delete', 'group_admin', 'lbr_teachers_operator_remove_listener');
    }

    /* Let the group owner and operators access to teacher acl of groups and subgroups */
    elgg_register_plugin_hook_handler('access:collections:write', 'all', 'lbr_teacher_write_acl_plugin_hook_groups', 10000); /* Run last
     * to remove group access for teachers. Only show them teacher+group access. */

    /* Let members have access to teacher acl of subgroups */
    elgg_register_plugin_hook_handler('access:collections:write', 'all', 'lbr_teacher_write_acl_plugin_hook_alumni');
}

elgg_register_event_handler('init', 'system', 'lbr_teachers_init');
