<?php declare(strict_types = 1);

// odsl-/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Reports_Aggregator.php-PHPStan\BetterReflection\Reflection\ReflectionClass-ORAS\Tickets\Admin\Reports_Aggregator
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.3.6-0e612a173b713d9367400f9425b5c00456ebd69a5164d5cb7e1a72e974f6dce3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'filename' => '/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Reports_Aggregator.php',
      ),
    ),
    'namespace' => 'ORAS\\Tickets\\Admin',
    'name' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
    'shortName' => 'Reports_Aggregator',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 13,
    'endLine' => 729,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
      'CACHE_TTL' => 
      array (
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'name' => 'CACHE_TTL',
        'modifiers' => 4,
        'type' => NULL,
        'value' => 
        array (
          'code' => '600',
          'attributes' => 
          array (
            'startLine' => 16,
            'endLine' => 16,
            'startTokenPos' => 55,
            'startFilePos' => 257,
            'endTokenPos' => 55,
            'endFilePos' => 259,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 16,
        'endLine' => 16,
        'startColumn' => 3,
        'endColumn' => 32,
      ),
    ),
    'immediateProperties' => 
    array (
    ),
    'immediateMethods' => 
    array (
      'get_aggregates' => 
      array (
        'name' => 'get_aggregates',
        'parameters' => 
        array (
          'event_id' => 
          array (
            'name' => 'event_id',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'int',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 24,
            'endLine' => 24,
            'startColumn' => 34,
            'endColumn' => 46,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'statuses' => 
          array (
            'name' => 'statuses',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 24,
            'endLine' => 24,
            'startColumn' => 49,
            'endColumn' => 63,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'date_range' => 
          array (
            'name' => 'date_range',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 24,
                'endLine' => 24,
                'startTokenPos' => 82,
                'startFilePos' => 549,
                'endTokenPos' => 83,
                'endFilePos' => 550,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 24,
            'endLine' => 24,
            'startColumn' => 66,
            'endColumn' => 87,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param int $event_id
 * @param string[] $statuses
 * @param array{after?:string,before?:string} $date_range
 * @return array{summary:array,by_ticket:array,phase_breakdown:array}
 */',
        'startLine' => 24,
        'endLine' => 358,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'iterate_order_items' => 
      array (
        'name' => 'iterate_order_items',
        'parameters' => 
        array (
          'event_id' => 
          array (
            'name' => 'event_id',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'int',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 366,
            'endLine' => 366,
            'startColumn' => 39,
            'endColumn' => 51,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'statuses' => 
          array (
            'name' => 'statuses',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 366,
            'endLine' => 366,
            'startColumn' => 54,
            'endColumn' => 68,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'date_range' => 
          array (
            'name' => 'date_range',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 366,
            'endLine' => 366,
            'startColumn' => 71,
            'endColumn' => 87,
            'parameterIndex' => 2,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 366,
            'endLine' => 366,
            'startColumn' => 90,
            'endColumn' => 107,
            'parameterIndex' => 3,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param int $event_id
 * @param string[] $statuses
 * @param array{after?:string,before?:string} $date_range
 * @param callable $callback
 */',
        'startLine' => 366,
        'endLine' => 444,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'iterate_orders' => 
      array (
        'name' => 'iterate_orders',
        'parameters' => 
        array (
          'event_id' => 
          array (
            'name' => 'event_id',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'int',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 452,
            'endLine' => 452,
            'startColumn' => 35,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'statuses' => 
          array (
            'name' => 'statuses',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 452,
            'endLine' => 452,
            'startColumn' => 50,
            'endColumn' => 64,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'date_range' => 
          array (
            'name' => 'date_range',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 452,
            'endLine' => 452,
            'startColumn' => 67,
            'endColumn' => 83,
            'parameterIndex' => 2,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 452,
            'endLine' => 452,
            'startColumn' => 86,
            'endColumn' => 103,
            'parameterIndex' => 3,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param int $event_id
 * @param string[] $statuses
 * @param array{after?:string,before?:string} $date_range
 * @param callable $callback
 */',
        'startLine' => 452,
        'endLine' => 487,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'get_item_ticket_context' => 
      array (
        'name' => 'get_item_ticket_context',
        'parameters' => 
        array (
          'item' => 
          array (
            'name' => 'item',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 493,
            'endLine' => 493,
            'startColumn' => 44,
            'endColumn' => 48,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param \\WC_Order_Item_Product|\\WC_Order_Item $item
 * @return array{event_id:int,ticket_index:string,ticket_name:string}
 */',
        'startLine' => 493,
        'endLine' => 516,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'normalize_statuses' => 
      array (
        'name' => 'normalize_statuses',
        'parameters' => 
        array (
          'statuses' => 
          array (
            'name' => 'statuses',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 522,
            'endLine' => 522,
            'startColumn' => 39,
            'endColumn' => 53,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param string[] $statuses
 * @return string[]
 */',
        'startLine' => 522,
        'endLine' => 535,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'get_cache_key' => 
      array (
        'name' => 'get_cache_key',
        'parameters' => 
        array (
          'event_id' => 
          array (
            'name' => 'event_id',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'int',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 542,
            'endLine' => 542,
            'startColumn' => 34,
            'endColumn' => 46,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'statuses' => 
          array (
            'name' => 'statuses',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 542,
            'endLine' => 542,
            'startColumn' => 49,
            'endColumn' => 63,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'date_range' => 
          array (
            'name' => 'date_range',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 542,
            'endLine' => 542,
            'startColumn' => 66,
            'endColumn' => 82,
            'parameterIndex' => 2,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param int $event_id
 * @param string[] $statuses
 * @param array{after?:string,before?:string} $date_range
 */',
        'startLine' => 542,
        'endLine' => 551,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'build_cache_key' => 
      array (
        'name' => 'build_cache_key',
        'parameters' => 
        array (
          'filters' => 
          array (
            'name' => 'filters',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 556,
            'endLine' => 556,
            'startColumn' => 36,
            'endColumn' => 49,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'scope' => 
          array (
            'name' => 'scope',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 556,
            'endLine' => 556,
            'startColumn' => 52,
            'endColumn' => 64,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param array<string,mixed> $filters
 */',
        'startLine' => 556,
        'endLine' => 561,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'sort_filter_array' => 
      array (
        'name' => 'sort_filter_array',
        'parameters' => 
        array (
          'filters' => 
          array (
            'name' => 'filters',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 567,
            'endLine' => 567,
            'startColumn' => 38,
            'endColumn' => 51,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param array<string,mixed> $filters
 * @return array<string,mixed>
 */',
        'startLine' => 567,
        'endLine' => 586,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'is_list_array' => 
      array (
        'name' => 'is_list_array',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 591,
            'endLine' => 591,
            'startColumn' => 34,
            'endColumn' => 45,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param array<string|int,mixed> $value
 */',
        'startLine' => 591,
        'endLine' => 595,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'build_date_created_arg' => 
      array (
        'name' => 'build_date_created_arg',
        'parameters' => 
        array (
          'date_range' => 
          array (
            'name' => 'date_range',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 600,
            'endLine' => 600,
            'startColumn' => 43,
            'endColumn' => 59,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param array{after?:string,before?:string} $date_range
 */',
        'startLine' => 600,
        'endLine' => 618,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'get_presale_key_map' => 
      array (
        'name' => 'get_presale_key_map',
        'parameters' => 
        array (
          'event_id' => 
          array (
            'name' => 'event_id',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'int',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 625,
            'endLine' => 625,
            'startColumn' => 40,
            'endColumn' => 52,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Presale is the earliest configured phase key by start datetime per ticket.
 *
 * @return array<string,string|null>
 */',
        'startLine' => 625,
        'endLine' => 677,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'get_phase_snapshot' => 
      array (
        'name' => 'get_phase_snapshot',
        'parameters' => 
        array (
          'item' => 
          array (
            'name' => 'item',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 685,
            'endLine' => 685,
            'startColumn' => 39,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * \'__none__\' indicates no phase snapshot meta was present on the order item.
 *
 * @param \\WC_Order_Item_Product|\\WC_Order_Item $item
 * @return array{key:string,label:string}
 */',
        'startLine' => 685,
        'endLine' => 705,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
      'phase_start_to_timestamp' => 
      array (
        'name' => 'phase_start_to_timestamp',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 707,
            'endLine' => 707,
            'startColumn' => 45,
            'endColumn' => 57,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionUnionType',
          'data' => 
          array (
            'types' => 
            array (
              0 => 
              array (
                'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                'data' => 
                array (
                  'name' => 'int',
                  'isIdentifier' => true,
                ),
              ),
              1 => 
              array (
                'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                'data' => 
                array (
                  'name' => 'null',
                  'isIdentifier' => true,
                ),
              ),
            ),
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 707,
        'endLine' => 728,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Admin',
        'declaringClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'implementingClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'currentClassName' => 'ORAS\\Tickets\\Admin\\Reports_Aggregator',
        'aliasName' => NULL,
      ),
    ),
    'traitsData' => 
    array (
      'aliases' => 
      array (
      ),
      'modifiers' => 
      array (
      ),
      'precedences' => 
      array (
      ),
      'hashes' => 
      array (
      ),
    ),
  ),
));