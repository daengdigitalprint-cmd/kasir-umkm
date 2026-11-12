<?php

beforeEach(function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://kasirumkm.web.id']);
});

afterEach(function () {
    $this->app['env'] = 'testing';
});

test('www dialihkan 301 ke host utama dengan path & query utuh', function () {
    $this->get('https://www.kasirumkm.web.id/panduan?x=1')
        ->assertStatus(301)
        ->assertRedirect('https://kasirumkm.web.id/panduan?x=1');
});

test('http dialihkan 301 ke https', function () {
    $this->get('http://kasirumkm.web.id/privacy')
        ->assertStatus(301)
        ->assertRedirect('https://kasirumkm.web.id/privacy');
});

test('https di host utama tidak dialihkan', function () {
    $this->get('https://kasirumkm.web.id/robots.txt')->assertOk();
});

test('https di belakang proxy (X-Forwarded-Proto) tidak dialihkan', function () {
    $this->withHeader('X-Forwarded-Proto', 'https')
        ->get('http://kasirumkm.web.id/robots.txt')
        ->assertOk();
});

test('POST tidak dialihkan', function () {
    $response = $this->post('https://www.kasirumkm.web.id/login', []);
    expect($response->getStatusCode())->not->toBe(301);
});
