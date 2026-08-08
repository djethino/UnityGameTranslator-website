@include('errors.layout', [
    'code' => '403',
    'title' => __('errors.forbidden'),
    'message' => __('errors.forbidden_message'),
])
