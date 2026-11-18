<?php

use App\Models\User;

test('public registration is disabled for both form and submission', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Registro público', 'username' => 'publico',
        'email' => 'publico@example.com', 'password' => 'Una-clave-publica-123',
        'password_confirmation' => 'Una-clave-publica-123',
    ])->assertNotFound();

    expect(User::count())->toBe(0);
});

test('staff can log in with username without an email or email verification', function () {
    $user = User::factory()->unverified()->create(['username' => 'mozo01', 'email' => null]);

    $this->post(route('login.store'), [
        'username' => 'MOZO01', 'password' => 'password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('profile.edit'))->assertOk();
});

test('inactive users cannot log in even with their correct password', function () {
    $user = User::factory()->create(['activo' => false]);

    $this->post(route('login.store'), [
        'username' => $user->username, 'password' => 'password',
    ])->assertSessionHasErrors('username');

    $this->assertGuest();
});

test('deactivating a staff member also ends an existing session', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $user->update(['activo' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});
