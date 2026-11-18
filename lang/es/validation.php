<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'present' => 'El campo :attribute debe estar presente.',
    'string' => 'El campo :attribute debe ser un texto.',
    'array' => 'El campo :attribute debe ser una lista.',
    'list' => 'El campo :attribute debe ser una lista ordenada.',
    'boolean' => 'Selecciona un valor válido para :attribute.',
    'integer' => 'Selecciona un valor válido para :attribute.',
    'email' => 'Ingresa un correo electrónico válido.',
    'unique' => 'El valor de :attribute ya está registrado.',
    'exists' => 'El valor seleccionado para :attribute no es válido.',
    'in' => 'El valor seleccionado para :attribute no es válido.',
    'distinct' => 'El campo :attribute contiene valores repetidos.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'prohibited' => 'El campo :attribute no puede modificarse aquí.',
    'regex' => 'El formato de :attribute no es válido.',
    'min' => [
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
        'array' => 'El campo :attribute debe contener al menos :min elementos.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
    ],
    'max' => [
        'string' => 'El campo :attribute no debe superar :max caracteres.',
        'array' => 'El campo :attribute no debe superar :max elementos.',
        'numeric' => 'El campo :attribute no debe superar :max.',
    ],
    'attributes' => [
        'name' => 'nombre', 'username' => 'usuario', 'email' => 'correo',
        'roleId' => 'rol', 'password' => 'contraseña', 'password_confirmation' => 'confirmación de contraseña',
        'newPassword' => 'nueva contraseña', 'newPassword_confirmation' => 'confirmación de contraseña',
    ],
];
