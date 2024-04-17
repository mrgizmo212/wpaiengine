<?php

class MeowPro_MWAI_FunctionAware {
  private $core = null;

  function __construct( $core ) {
    $this->core = $core;
    add_filter( 'mwai_chatbot_query', array( $this, 'chatbot_query' ), 10, 2 );
    add_filter( 'mwai_ai_feedback', array( $this, 'ai_feedbacks' ), 10, 2 );
    add_filter( 'mwai_functions_list', array( $this, 'functions_list' ), 10, 1 );
  }

  function functions_list( $functions ) {
    global $mwcode;
		if ( isset( $mwcode ) ) {
			$more_functions = $mwcode->get_functions();
      if ( !empty( $more_functions ) ) {
        $functions = array_merge( $functions, $more_functions );
      }
		}
    return $functions;
  }

  function ai_feedbacks( $value, $functionCall ) {
    $function = $functionCall['function'];
    if ( empty( $function ) || empty( $function->id ) ) {
      return $value;
    }
    $arguments = $functionCall['arguments'] ?? [];
    // Not sure why Anthropic is sending an object with a type of 'object' when there is nothing
    // in the object. This is a workaround for that.
    if ( is_array( $arguments ) && count( $arguments ) === 1 && 
      isset( $arguments['type'] ) && $arguments['type'] === 'object' ) {
      $arguments = [];
    }
    global $mwcode;
    if ( empty( $mwcode ) ) {
      error_log("AI Engine: Snippet Vault is not available.");
      return $value;
    }
    $value = $mwcode->execute_function( $function->id, $functionCall['arguments'] );
    return $value;
  }

  function chatbot_query( $query, $params ) {
    $functions = $params['functions'] ?? [];
    foreach ( $functions as $function ) {
      $type = $function['type'] ?? null;
      if ( $type !== 'snippet-vault' ) {
        error_log("AI Engine: The type '{$type}' for the function is not supported.");
        continue;
      }
      global $mwcode;
      if ( empty( $mwcode ) ) {
        error_log("AI Engine: Snippet Vault is not available.");
        continue;
      }
      $func = $mwcode->get_function( $function['id'] ?? null );
      if ( empty( $func ) ) {
        error_log("AI Engine: The function '{$function['id']}' was not found.");
        continue;
      }
      $args = [];
      foreach ( $func['args'] as $arg ) {
        $name = $arg['name'] ?? "";
        if ( substr( $name, 0, 1 ) === '$' ) {
          error_log("AI Engine: The argument '{$name}' should not start with a dollar sign.");
          $name = substr( $name, 1 );
        }
        $desc = $arg['desc'] ?? "";
        $type = $arg['type'] ?? "string";
        $required = $arg['required'] ?? true;
        $args[] = new Meow_MWAI_Query_Parameter( $name, $desc, $type, $required );
      }
      $query->add_function( new Meow_MWAI_Query_Function( $func['name'], $func['desc'],
        $args, 'PHP', $func['snippetId'] ) );
    }
    return $query;
  }
}
