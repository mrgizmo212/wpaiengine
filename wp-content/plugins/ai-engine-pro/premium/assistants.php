<?php

class MeowPro_MWAI_Assistants {
  private $core = null;
  private $namespace = 'mwai/v1/';

  function __construct( $core ) {
    $this->core = $core;
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
    add_action( 'mwai_ai_query_assistant', [ $this, 'query_assistant' ], 10, 2 );
    // Handle mwai_files_delete
    add_filter( 'mwai_files_delete', [ $this, 'files_delete_filter' ], 10, 2 );
  }

  #region REST API

  function rest_api_init() {
    register_rest_route( $this->namespace, '/openai/assistants/list', [
      'methods' => 'GET',
      'permission_callback' => [ $this->core, 'can_access_settings' ],
      'callback' => [ $this, 'rest_assistants_list' ],
    ] );
  }

  function rest_assistants_list( $request ) {
    try {
      $envId = $request->get_param( 'envId' );
      $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );
      $rawAssistants = [];
      $hasMore = true;
      $lastId = null;
      while ( $hasMore ) {
        $query = '/assistants?limit=25';
        if ($lastId !== null) {
          $query .= '&after=' . $lastId;
        }
        $res = $openai->execute( 'GET', $query, null, null, true, [
          'OpenAI-Beta' => 'assistants=v1'
        ] );
        $data = $res['data'];
        $rawAssistants = array_merge( $rawAssistants, $data );
        $lastId = $res['last_id'];
        $hasMore = $res['has_more'];
      }

      $assistants = array_map( function ( $assistant ) {
        $assistant['files_count'] = count( $assistant['file_ids'] );
        $assistant['createdOn'] = date( 'Y-m-d H:i:s', $assistant['created_at'] );
        $has_code_interpreter = false;
        $has_retrieval = false;
        foreach ( $assistant['tools'] as $tool ) {
          if ( $tool['type'] === 'code_interpreter' ) {
            $has_code_interpreter = true;
          }
          if ( $tool['type'] === 'retrieval' ) {
            $has_retrieval = true;
          }
        }
        $assistant['has_code_interpreter'] = $has_code_interpreter;
        $assistant['has_retrieval'] = $has_retrieval;
        unset( $assistant['file_ids'] );
        unset( $assistant['metadata'] );
        unset( $assistant['tools'] );
        unset( $assistant['created_at'] );
        unset( $assistant['updated_at'] );
        unset( $assistant['deleted_at'] );
        unset( $assistant['tools'] );
        unset( $assistant['object'] );
        return $assistant;
      }, $rawAssistants );
      $this->core->update_ai_env( $envId, 'assistants', $assistants );
      return new WP_REST_Response([ 'success' => true, 'assistants' => $assistants ], 200 ); 
    }
    catch ( Exception $e ) {
			$message = apply_filters( 'mwai_ai_exception', $e->getMessage() );
			return new WP_REST_Response([ 'success' => false, 'message' => $message ], 500 );
		}
  }

  #endregion

  #region Files Delete Filter
  public function get_env_id_from_assistant_id( $assistantId ) {
    $envs = $this->core->get_option( 'ai_envs' );
    foreach ( $envs as $env ) {
      if ( !empty( $env['assistants'] ) ) {
        foreach ( $env['assistants'] as $assistant ) {
          if ( $assistant['id'] === $assistantId ) {
            return $env['id'];
          }
        }
      }
    }
    return null;
  }

  public function files_delete_filter( $refIds ) {
    foreach ( $refIds as $refId ) {
      $metadata = $this->core->files->get_metadata( $refId );
      $assistantId = $metadata['assistant_id'] ?? null;
      $threadId = $metadata['assistant_threadId'] ?? null;
      if ( !empty( $assistantId ) && !empty( $threadId ) ) {
        $envId = $this->get_env_id_from_assistant_id( $assistantId );
        if ( !empty( $envId ) ) {
          $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );
          try {
            $openai->execute( 'DELETE', "/files/{$refId}", null, null, true, [ 'OpenAI-Beta' => 'assistants=v1' ] );
          }
          catch ( Exception $e ) {
            error_log( $e->getMessage() );
          }
        }
      }
    }
    return $refIds;
  }
  #endregion

  #region Chatbot or Forms Takeover by Assistant

  function create_message( $openai, $threadId, $assistantId, $query ) {
    $body = [ 'role' => 'user', 'content' => $query->message ];
    if ( !empty( $query->file ) ) {
      if ( $query->fileType !== 'refId' || $query->filePurpose !== 'assistant-in' ) {
        throw new Exception( 'The file type should be refId and the file purpose should be assistant-in.' );
      }
      $body['file_ids'] = [ $query->file ];
      $fileId = $this->core->files->get_id_from_refId( $query->file );
      $this->core->files->add_metadata( $fileId, 'assistant_id', $assistantId );
      $this->core->files->add_metadata( $fileId, 'assistant_threadId', $threadId );

    }
    foreach ( $query->messages as $message ) {
      if ( !empty( $message['functions'] ) ) {
        $body['functions'] = $message['functions'];
        $body['function_call'] = $message['function_call'];
      }
    }
    $res = $openai->execute( 'POST', "/threads/{$threadId}/messages", $body, null, true, 
      [ 'OpenAI-Beta' => 'assistants=v1' ]
    );
    return $res['id'];
  }

  function create_run( $openai, $threadId, $assistantId, $query ) {
    $body = [ 'assistant_id' => $assistantId ];
    if ( !empty( $query->instructions ) ) {
      $body['additional_instructions'] = $query->instructions;
    }
    if ( !empty( $query->context ) ) {
      if ( isset( $body['additional_instructions'] ) ) {
        $body['additional_instructions'] .= "\n";
      }
      else {
        $body['additional_instructions'] = "";
      }
      $body['additional_instructions'] .= "Additional context:\n" . $query->context;
    }
    $body['assistant_id'] = $assistantId;
    $res = $openai->execute( 'POST', "/threads/{$threadId}/runs", $body, null, true, [ 'OpenAI-Beta' => 'assistants=v1' ] );
    return $res['id'];
  }

  function handle_run( $openai, $threadId, $runId ) {
    do {
      sleep( 0.25 ); // Consider implementing exponential backoff or similar strategy.
      $res = $openai->execute( 'GET', "/threads/{$threadId}/runs/{$runId}", null, null, true, [ 'OpenAI-Beta' => 'assistants=v1' ] );
      $status = $res['status'];
    }
    while ( in_array( $status, ['running', 'queued', 'in_progress'] ) );
    return $this->handle_run_actions( $res, $openai, $threadId, $runId );
  }

  function handle_run_actions( $res, $openai, $threadId, $runId ) {
    $runStatus = $res['status'];
    if ( $runStatus === 'failed' ) {
      if ( isset( $res['last_error']['message'] ) ) {
        $message = $res['last_error']['message'];
        throw new Exception( $message );
      }
      else {
        throw new Exception( 'Unknown error.' );
      }
    }

    if ( $runStatus === 'requires_action' ) {
      $functions = [];
      $calls = [];
    
      // First, let's collect the function definitions.
      foreach ( $res['tools'] as $tool ) {
        if ( $tool['type'] === 'function' ) {
          $functionDetails = $tool['function'];
          $parameters = [];
    
          foreach ( $functionDetails['parameters']['properties'] as $paramKey => $paramValue ) {
            $parameters[] = new Meow_MWAI_Query_Parameter(
              $paramKey,
              isset( $paramValue['description'] ) ? $paramValue['description'] : '',
              isset( $paramValue['type'] ) ? $paramValue['type'] : 'string',
              in_array( $paramKey, $functionDetails['parameters']['required'] )
            );
          }
    
          // Create new function with the details.
          $functions[$functionDetails['name']] = new Meow_MWAI_Query_Function(
            $functionDetails['name'],
            $functionDetails['description'],
            $parameters
          );
        }
      }
    
      // Then let's process the calls.
      foreach ( $res['required_action']['submit_tool_outputs']['tool_calls'] as $call ) {
        $callId = $call['id'];
        $funcName = $call['function']['name'];
        $funcArgs = $call['function']['arguments'];
        $decodedFuncArgs = json_decode( $funcArgs, true );
    
        // Now, match the call to the function definition.
        if ( array_key_exists( $funcName, $functions ) ) {
          $parameterValues = [];
    
          foreach ( $decodedFuncArgs as $argKey => $argValue ) {
            $parameterValues[$argKey] = $argValue;
          }
    
          // Store the call with its matched function and parameter values.
          $calls[] = [
            'id' => $callId,
            'func' => $functions[$funcName],
            'args' => $parameterValues
          ];
        }
      }
      $tool_outputs = [];
      foreach ( $calls as $call ) {
        $value = apply_filters( 'mwai_ai_function', null, $call['func']->name, $call['args'] );
        if ( $value !== null ) {
          $tool_outputs[] = [ 'tool_call_id' => $call['id'], 'output' => $value ];
        }
      }
      if ( empty( $tool_outputs ) ) {
        throw new Exception( 'This assistant use functions. In this case, the function "' . $call['func']->name . '" was called with the arguments ' . json_encode( $call['args'] ) . '. Please use the mwai_ai_function filter to handle this.' );
      }
      $body = [ 'tool_outputs' => $tool_outputs ];
      $res = $openai->execute( 'POST', "/threads/{$threadId}/runs/{$runId}/submit_tool_outputs", $body, null, true, [ 'OpenAI-Beta' => 'assistants=v1' ] );
      return $this->handle_run( $openai, $threadId, $runId );
    } 
    return $runStatus;
  }   

  function query_assistant( $reply, $query ) {
    $envId = $query->envId;
    $assistantId = $query->assistantId;
    // If it's a form, there is no chatId, a new one will be generated, and a new thread will be created.
    $chatId = !empty( $query->chatId ) ? $query->chatId : $this->core->get_random_id( 10 );
    if ( empty( $envId ) || empty( $assistantId ) ) {
      throw new Exception( 'Assistant requires an envId and an assistantId.' );
    }
    $assistant = $this->core->get_assistant( $envId, $assistantId );
    if ( empty( $assistant ) ) {
      throw new Exception( 'Assistant not found.' );
    }
    $query->set_model( $assistant['model'] );
    $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );

    // We will use the $chatId to see if there are any previous conversations.
    // If not, we need to create a new thread.
    $chat = $this->core->discussions->get_discussion( $query->botId, $chatId );
    $threadId = $chat->threadId ?? null;
    
    // Create Thread
    if ( empty( $threadId ) ) {
      $body = [ 'metadata' => [ 'chatId' => $chatId ] ];
      $body['messages'] = [];
      $res = $openai->execute( 'POST', '/threads', $body, null, true, [ 'OpenAI-Beta' => 'assistants=v1' ] );
      $threadId = $res['id'];
    }

    // Create Message
    $this->create_message( $openai, $threadId, $assistantId, $query );

    // Create Run with support for Instructions and Context
    $runId = $this->create_run( $openai, $threadId, $assistantId, $query );

    // Wait for the run to complete
    $runStatus = $this->handle_run( $openai, $threadId, $runId );
    if ( $runStatus !== 'completed' ) {
      throw new Exception( 'The assistant run did not complete.' );
    }

    // Get Messages
    $res = $openai->execute( 'GET', "/threads/{$threadId}/messages", null, null, true, [ 'OpenAI-Beta' => 'assistants=v1' ] );
    $messages = $res['data'];
    $first = $messages[0];
    $content = $first['content'];
    $finalReply = "";
    foreach ( $content as $block ) {
      if ( $block['type'] === 'image_file' ) {
        $fileId = $block['image_file']['file_id'];
        $purpose = 'assistant-out';
        $tmpFile = $openai->download_file( $fileId );
        // Create a random image filename (since the assistant doesn't give us one)
        $filename = $this->core->get_random_id( 10 ) . '.png';
        $meta = [
          'assistant_id' => $assistantId,
          'assistant_threadId' => $threadId,
        ];
        if ( !empty( $block['image_file']['file_path'] ) ) {
          $meta['assistant_sandboxPath'] = $block['image_file']['file_path'];
        }
        $refId = $this->core->files->upload_file( $tmpFile, $filename, $purpose, $meta, $envId );
        $internalFileId = $this->core->files->get_id_from_refId( $refId );
        $this->core->files->update_refId( $internalFileId, $fileId );
        $url = $this->core->files->get_url( $fileId );
        $finalReply .= "![Image](" . $url . ")";
      }
      if ( $block['type'] === 'text' ) {
        $finalReply .= $block['text']['value'];
        // If there are annotations $block['annotations'], let's go through them
        if ( !empty( $block['text']['annotations'] ) ) {
          foreach ( $block['text']['annotations'] as $annotation ) {
            if ( $annotation['type'] === 'file_path' ) {
              $file = pathinfo( $annotation['text'] );
              $fileId = $annotation['file_path']['file_id'];
              $purpose = 'assistant-out';
              $tmpFile = $openai->download_file( $fileId );
              $refId = $this->core->files->upload_file( $tmpFile, $file['name'], $purpose, [
                'assistant_id' => $assistantId,
                'assistant_threadId' => $threadId,
                'assistant_sandboxPath' => $annotation['text']
              ], $envId );
              $internalFileId = $this->core->files->get_id_from_refId( $refId );
              $this->core->files->update_refId( $internalFileId, $fileId );
              $url = $this->core->files->get_url( $fileId );
              $escapedAnnotationText = preg_quote( $annotation['text'], '/' );
              $finalReply = preg_replace( '/' . $escapedAnnotationText . '/', $url, $finalReply, 1 );
            }
          }
        }
        break;
      }
    }

    // If there are still sandbox elements, let's replace them with the URLs if we have them.
    if ( strpos( $finalReply, 'sandbox:/mnt/data/' ) !== false ) {
      // Let's match all the filename like that: (sandbox:/mnt/data/net_gross_amount_over_time.png)
      //preg_match_all( '/\(sandbox:\/mnt\/data\/(.*?)\)/', $finalReply, $matches );
      preg_match_all( '/\((sandbox:\/mnt\/data\/.*?)\)/', $finalReply, $matches );
      if ( !empty( $matches[1] ) ) {
        foreach ( $matches[1] as $match ) {
          $file = pathinfo( $match );
          $files = $this->core->files->search( $this->core->get_user_id(), 'assistant-out', [
            'assistant_id' => $assistantId,
            'assistant_threadId' => $threadId,
            'assistant_sandboxPath' => $match
          ], $query->envId );
          if ( !empty( $files ) ) {
            $fileId = $files[0]['refId'];
            $url = $this->core->files->get_url( $fileId );
            $escapedMatch = preg_quote( $match, '/' );
            $finalReply = preg_replace( '/' . $escapedMatch . '/', $url, $finalReply, 1 );
          }
        }
      }
    }

    if ( empty( $finalReply ) ) {
      throw new Exception( "No text reply from the assistant." );
    }

    // Let's clean the $finalReply a bit more.
    $pattern = '/【\d+†source】/';
    $finalReply = preg_replace( $pattern, '', $finalReply );

    // TODO: In fact, this threadId should probably be in the query.
    // The Discussions Module will also use that threadId. Currently, it's getting it from the $params.
    $query->setThreadId( $threadId );
    $reply = new Meow_MWAI_Reply( $query );
    $reply->set_choices( $finalReply );
    $reply->set_type( 'assistant' );
    $in_tokens = Meow_MWAI_Core::estimate_tokens( $query->messages, $query->message );
    $out_tokens = Meow_MWAI_Core::estimate_tokens( $reply->result );
    $usage = $this->core->record_tokens_usage( $query->model, $in_tokens, $out_tokens );
    $reply->set_usage( $usage );
    return $reply;
  }
  #endregion
}