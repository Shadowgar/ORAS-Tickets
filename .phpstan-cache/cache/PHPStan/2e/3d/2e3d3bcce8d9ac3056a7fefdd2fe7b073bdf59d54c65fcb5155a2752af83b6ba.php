<?php declare(strict_types = 1);

// odsl-/home/rocco/projects/ORAS-Tickets/plugin/includes/Domain/Ticket_Collection.php-PHPStan\BetterReflection\Reflection\ReflectionClass-ORAS\Tickets\Domain\Ticket_Collection
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.3.6-ad02569518464d4210ab38521ab8d7825d793efae37cd38007f36216a20b0fb1',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'filename' => '/home/rocco/projects/ORAS-Tickets/plugin/includes/Domain/Ticket_Collection.php',
      ),
    ),
    'namespace' => 'ORAS\\Tickets\\Domain',
    'name' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
    'shortName' => 'Ticket_Collection',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 11,
    'endLine' => 172,
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
      'tickets' => 
      array (
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'name' => 'tickets',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '[]',
          'attributes' => 
          array (
            'startLine' => 15,
            'endLine' => 15,
            'startTokenPos' => 48,
            'startFilePos' => 206,
            'endTokenPos' => 49,
            'endFilePos' => 207,
          ),
        ),
        'docComment' => '/** @var Ticket[] */',
        'attributes' => 
        array (
        ),
        'startLine' => 15,
        'endLine' => 15,
        'startColumn' => 2,
        'endColumn' => 29,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
    ),
    'immediateMethods' => 
    array (
      '__construct' => 
      array (
        'name' => '__construct',
        'parameters' => 
        array (
          'tickets' => 
          array (
            'name' => 'tickets',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 17,
                'endLine' => 17,
                'startTokenPos' => 64,
                'startFilePos' => 259,
                'endTokenPos' => 65,
                'endFilePos' => 260,
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
            'startLine' => 17,
            'endLine' => 17,
            'startColumn' => 30,
            'endColumn' => 48,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 17,
        'endLine' => 20,
        'startColumn' => 2,
        'endColumn' => 2,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Domain',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'aliasName' => NULL,
      ),
      'load_for_event' => 
      array (
        'name' => 'load_for_event',
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
            'startLine' => 32,
            'endLine' => 32,
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
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Envelope stored in postmeta:
 * [
 *   \'schema\'  => 1,
 *   \'tickets\' => [ ticket_key => [ ...Ticket fields... ], ... ]
 * ]
 *
 * Returns a Ticket_Collection instance. If the stored envelope is missing
 * or the schema is not 1, an empty collection is returned.
 */',
        'startLine' => 32,
        'endLine' => 62,
        'startColumn' => 2,
        'endColumn' => 2,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'ORAS\\Tickets\\Domain',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'aliasName' => NULL,
      ),
      'load_envelope_for_event' => 
      array (
        'name' => 'load_envelope_for_event',
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
            'startLine' => 72,
            'endLine' => 72,
            'startColumn' => 49,
            'endColumn' => 61,
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
 * Return the raw envelope array from postmeta. Useful for callers that
 * still need the original shape.
 *
 * Always returns an envelope with \'schema\' and \'tickets\' keys.
 *
 * @return array{schema:int,tickets:array}
 */',
        'startLine' => 72,
        'endLine' => 90,
        'startColumn' => 2,
        'endColumn' => 2,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'ORAS\\Tickets\\Domain',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'aliasName' => NULL,
      ),
      'save_for_event' => 
      array (
        'name' => 'save_for_event',
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
            'startLine' => 92,
            'endLine' => 92,
            'startColumn' => 40,
            'endColumn' => 52,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'envelope' => 
          array (
            'name' => 'envelope',
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
            'startLine' => 92,
            'endLine' => 92,
            'startColumn' => 55,
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
        'startLine' => 92,
        'endLine' => 142,
        'startColumn' => 2,
        'endColumn' => 2,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'ORAS\\Tickets\\Domain',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'aliasName' => NULL,
      ),
      'all' => 
      array (
        'name' => 'all',
        'parameters' => 
        array (
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
 * Return tickets as an ordered array of Ticket objects.
 * @return Ticket[]
 */',
        'startLine' => 148,
        'endLine' => 151,
        'startColumn' => 2,
        'endColumn' => 2,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Domain',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'aliasName' => NULL,
      ),
      'count' => 
      array (
        'name' => 'count',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 153,
        'endLine' => 156,
        'startColumn' => 2,
        'endColumn' => 2,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Domain',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'aliasName' => NULL,
      ),
      'is_empty' => 
      array (
        'name' => 'is_empty',
        'parameters' => 
        array (
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
        'docComment' => NULL,
        'startLine' => 158,
        'endLine' => 161,
        'startColumn' => 2,
        'endColumn' => 2,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'ORAS\\Tickets\\Domain',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'aliasName' => NULL,
      ),
      'generate_ticket_key' => 
      array (
        'name' => 'generate_ticket_key',
        'parameters' => 
        array (
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
 * Create a new ticket_key.
 * Must be stable, unique per event, and safe for array keys.
 */',
        'startLine' => 167,
        'endLine' => 171,
        'startColumn' => 2,
        'endColumn' => 2,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'ORAS\\Tickets\\Domain',
        'declaringClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'implementingClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
        'currentClassName' => 'ORAS\\Tickets\\Domain\\Ticket_Collection',
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