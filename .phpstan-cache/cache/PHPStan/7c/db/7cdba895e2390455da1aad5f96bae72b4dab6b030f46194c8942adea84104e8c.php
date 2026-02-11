<?php declare(strict_types = 1);

// odsl-/home/rocco/projects/ORAS-Tickets/plugin/includes/Commerce/Woo/Capacity_Consumption.php-PHPStan\BetterReflection\Reflection\ReflectionClass-ORAS\Tickets\Commerce\Woo\Capacity_Consumption
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.3.6-a7802de5c44a031cf5aa07e1807271bf833b3fdd19cf6e07c6dc6ca7913e768b',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'filename' => '/home/rocco/projects/ORAS-Tickets/plugin/includes/Commerce/Woo/Capacity_Consumption.php',
      ),
    ),
    'namespace' => 'ORAS\\Tickets\\Commerce\\Woo',
    'name' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
    'shortName' => 'Capacity_Consumption',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 12,
    'endLine' => 277,
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
    ),
    'immediateProperties' => 
    array (
    ),
    'immediateMethods' => 
    array (
      'register' => 
      array (
        'name' => 'register',
        'parameters' => 
        array (
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
        'docComment' => NULL,
        'startLine' => 15,
        'endLine' => 21,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Commerce\\Woo',
        'declaringClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'implementingClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'currentClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'aliasName' => NULL,
      ),
      'handle_paid_order' => 
      array (
        'name' => 'handle_paid_order',
        'parameters' => 
        array (
          'order_id' => 
          array (
            'name' => 'order_id',
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
            'startLine' => 28,
            'endLine' => 28,
            'startColumn' => 37,
            'endColumn' => 49,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Consume capacity for ORAS ticket line items when the order is paid.
 *
 * @param int $order_id
 */',
        'startLine' => 28,
        'endLine' => 133,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Commerce\\Woo',
        'declaringClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'implementingClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'currentClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'aliasName' => NULL,
      ),
      'handle_restore_order' => 
      array (
        'name' => 'handle_restore_order',
        'parameters' => 
        array (
          'order_id' => 
          array (
            'name' => 'order_id',
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
            'startLine' => 140,
            'endLine' => 140,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Restore capacity for ORAS ticket line items when the order is cancelled/refunded.
 *
 * @param int $order_id
 */',
        'startLine' => 140,
        'endLine' => 249,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Commerce\\Woo',
        'declaringClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'implementingClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'currentClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'aliasName' => NULL,
      ),
      'sync_product_stock' => 
      array (
        'name' => 'sync_product_stock',
        'parameters' => 
        array (
          'product_id' => 
          array (
            'name' => 'product_id',
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
            'startLine' => 251,
            'endLine' => 251,
            'startColumn' => 39,
            'endColumn' => 53,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'remaining' => 
          array (
            'name' => 'remaining',
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
            'startLine' => 251,
            'endLine' => 251,
            'startColumn' => 56,
            'endColumn' => 69,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 251,
        'endLine' => 276,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'ORAS\\Tickets\\Commerce\\Woo',
        'declaringClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'implementingClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
        'currentClassName' => 'ORAS\\Tickets\\Commerce\\Woo\\Capacity_Consumption',
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