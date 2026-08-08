@include('errors.layout', [
    'code' => '500',
    'title' => __('errors.server_error'),
    'message' => __('errors.server_error_message'),
])
