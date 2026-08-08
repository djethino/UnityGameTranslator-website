@include('errors.layout', [
    'code' => '404',
    'title' => __('errors.not_found'),
    'message' => __('errors.not_found_message'),
])
