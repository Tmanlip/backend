<h2>Welcome, {{ $user->name }}!</h2>

<p>Your account has been created successfully. You can login using the following credentials:</p>

<ul>
    <li>Username: {{ $user->username }}</li>
    <li>Password: {{ $password }}</li>
</ul>

<p>Click the button below to login:</p>

<a href="{{ env('APP_FRONTEND_URL') }}" 
   style="display:inline-block;padding:10px 20px;background-color:#007bff;color:white;text-decoration:none;border-radius:5px;">
   Login Now
</a>

<p>Or copy and paste this URL in your browser:</p>
<p>{{ env('APP_FRONTEND_URL') }}/login</p>
