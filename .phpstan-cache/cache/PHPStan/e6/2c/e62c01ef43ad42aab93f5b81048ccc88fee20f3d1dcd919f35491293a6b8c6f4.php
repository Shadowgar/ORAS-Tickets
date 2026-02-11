<?php declare(strict_types = 1);

// odsl-/home/rocco/projects/ORAS-Tickets/plugin/includes/Domain/Pricing/Price_Resolver.php-PHPStan\BetterReflection\Reflection\ReflectionClass-ORAS\Tickets\Domain\Pricing\Price_Resolver
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.3.6-dafc86c295a9f869c33d6ccc2c4ccb80849b0003117f87ef821b9ff434647797',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'filename' => '/home/rocco/projects/ORAS-Tickets/plugin/includes/Domain/Pricing/Price_Resolver.php',
      ),
    ),
    'namespace' => 'ORAS\\Tickets\\Domain\\Pricing',
    'name' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
    'shortName' => 'Price_Resolver',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 9,
    'endLine' => 121,
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
      'resolve_ticket_price' => 
      array (
        'name' => 'resolve_ticket_price',
        'parameters' => 
        array (
          'ticket' => 
          array (
            'name' => 'ticket',
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
            'startColumn' => 47,
            'endColumn' => 59,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'now_utc_ts' => 
          array (
            'name' => 'now_utc_ts',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 24,
                'endLine' => 24,
                'startTokenPos' => 39,
                'startFilePos' => 556,
                'endTokenPos' => 39,
                'endFilePos' => 559,
              ),
            ),
            'type' => 
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 24,
            'endLine' => 24,
            'startColumn' => 62,
            'endColumn' => 84,
            'parameterIndex' => 1,
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
 * Resolve a ticket price based on ordered price phases.
 *
 * @param array    $ticket     Ticket data.
 * @param int|null $now_utc_ts Current UTC timestamp.
 *
 * @return array{
 *     price: string,
 *     phase_key: string|null,
 *     phase_label: string|null,
 *     phase_end_ts: int|null,
 * }
 */',
        'startLine' => 24,
        'endLine' => 75,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'ORAS\\Tickets\\Domain\\Pricing',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'aliasName' => NULL,
      ),
      'parse_utc_datetime_to_ts' => 
      array (
        'name' => 'parse_utc_datetime_to_ts',
        'parameters' => 
        array (
          'dt' => 
          array (
            'name' => 'dt',
            'default' => NULL,
            'type' => 
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
                      'name' => 'string',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 84,
            'endLine' => 84,
            'startColumn' => 52,
            'endColumn' => 62,
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
        'docComment' => '/**
 * Parse a UTC datetime string to a timestamp.
 *
 * @param string|null $dt Datetime string.
 *
 * @return int|null
 */',
        'startLine' => 84,
        'endLine' => 96,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 20,
        'namespace' => 'ORAS\\Tickets\\Domain\\Pricing',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'aliasName' => NULL,
      ),
      'normalize_price_string' => 
      array (
        'name' => 'normalize_price_string',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 105,
            'endLine' => 105,
            'startColumn' => 50,
            'endColumn' => 55,
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
                  'name' => 'string',
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
        'docComment' => '/**
 * Normalize a numeric price value to a string with 2 decimals.
 *
 * @param mixed $value Price value.
 *
 * @return string|null
 */',
        'startLine' => 105,
        'endLine' => 120,
        'startColumn' => 3,
        'endColumn' => 3,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 20,
        'namespace' => 'ORAS\\Tickets\\Domain\\Pricing',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Pricing\\Price_Resolver',
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