/**
 * Software CRM - WebAuthn Mobile Biometrics Client
 * Supports Fingerprint, Face ID, Touch ID, and Platform Authenticators.
 */

const BiometricAuth = {
  // Base64URL to ArrayBuffer
  base64urlToBuffer(base64url) {
    if (!base64url) return new Uint8Array(0).buffer;
    let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    while (base64.length % 4 !== 0) {
      base64 += '=';
    }
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
  },

  // Hex string to ArrayBuffer
  hexToBuffer(hex) {
    if (!hex) return new Uint8Array(0).buffer;
    const bytes = new Uint8Array(hex.length / 2);
    for (let i = 0; i < hex.length; i += 2) {
      bytes[i / 2] = parseInt(hex.substr(i, 2), 16);
    }
    return bytes.buffer;
  },

  // ArrayBuffer to Base64URL string
  bufferToBase64url(buffer) {
    if (!buffer) return '';
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
  },

  // Check if browser & platform hardware biometrics are supported
  async isPlatformAuthenticatorAvailable() {
    if (!window.PublicKeyCredential) {
      return false;
    }
    if (typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable === 'function') {
      try {
        return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
      } catch (e) {
        return false;
      }
    }
    return true;
  },

  // Detect friendly device name from User-Agent
  getDeviceName() {
    const ua = navigator.userAgent || '';
    if (/iPhone/i.test(ua)) return 'iPhone (Face ID / Touch ID)';
    if (/iPad/i.test(ua)) return 'iPad (Face ID / Touch ID)';
    if (/Android/i.test(ua)) {
      const match = ua.match(/;\s*([^;)]+)\s+Build\//i);
      if (match && match[1]) return match[1].trim() + ' (Fingerprint)';
      return 'Android Smartphone (Fingerprint)';
    }
    if (/Macintosh/i.test(ua)) return 'Mac (Touch ID)';
    if (/Windows/i.test(ua)) return 'Windows PC (Hello / Biometrics)';
    return 'Smartphone (Biometrics)';
  },

  // Format error messages human-readably
  formatError(e) {
    if (!e) return 'An unknown biometric error occurred.';
    const name = e.name || '';
    const msg = e.message || '';

    if (name === 'NotAllowedError') {
      return 'Biometric verification was cancelled or timed out.';
    }
    if (name === 'InvalidStateError') {
      return 'This biometric device is already registered for your account.';
    }
    if (name === 'NotSupportedError') {
      return 'Biometric authentication is not supported on this device or browser. Please use standard password login.';
    }
    if (name === 'AbortError') {
      return 'Biometric operation was interrupted.';
    }
    if (name === 'SecurityError') {
      return 'Biometric operation was blocked due to origin security policies. HTTPS or localhost is required.';
    }
    return msg || 'Biometric authentication failed. Please try again or use password login.';
  },

  /**
   * Enroll / Register this smartphone's platform authenticator
   */
  async registerDevice(deviceName = null) {
    if (!window.PublicKeyCredential) {
      throw new Error('WebAuthn biometrics is not supported by your browser.');
    }

    const available = await this.isPlatformAuthenticatorAvailable();
    if (!available) {
      throw new Error('No built-in biometric sensor (Fingerprint or Face ID) is available on this device.');
    }

    // 1. Fetch challenge from server
    const challengeRes = await fetch('api/biometric_auth.php?action=register_challenge', {
      method: 'POST',
      headers: { 'Accept': 'application/json' }
    });
    const options = await challengeRes.json();

    if (!options || !options.success) {
      throw new Error((options && options.error) ? options.error : 'Failed to initialize biometric enrollment challenge.');
    }

    // 2. Format options for navigator.credentials.create()
    const publicKey = {
      challenge: this.base64urlToBuffer(options.challenge),
      rp: options.rp,
      user: {
        id: this.hexToBuffer(options.user.id),
        name: options.user.name,
        displayName: options.user.displayName
      },
      pubKeyCredParams: options.pubKeyCredParams,
      authenticatorSelection: options.authenticatorSelection || {
        authenticatorAttachment: 'platform',
        userVerification: 'required',
        residentKey: 'preferred'
      },
      timeout: options.timeout || 60000,
      attestation: options.attestation || 'none'
    };

    // 3. Prompt OS native biometric (Face ID / Fingerprint)
    const credential = await navigator.credentials.create({ publicKey });
    if (!credential) {
      throw new Error('Biometric registration was cancelled.');
    }

    // 4. Send credential payload to server
    const payload = {
      action: 'register_credential',
      credential_id: credential.id,
      clientDataJSON: this.bufferToBase64url(credential.response.clientDataJSON),
      attestationObject: this.bufferToBase64url(credential.response.attestationObject),
      device_name: deviceName || this.getDeviceName()
    };

    const saveRes = await fetch('api/biometric_auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await saveRes.json();

    if (!result || !result.success) {
      throw new Error((result && result.error) ? result.error : 'Failed to register biometric device on server.');
    }

    return result;
  },

  /**
   * Authenticate via Fingerprint / Face ID
   */
  async loginWithBiometrics(email) {
    if (!email || !email.trim()) {
      throw new Error('Please enter your registered email address first.');
    }

    if (!window.PublicKeyCredential) {
      throw new Error('WebAuthn biometrics is not supported by your browser.');
    }

    // 1. Fetch challenge and allowed credentials for this email
    const challengeRes = await fetch('api/biometric_auth.php?action=login_challenge', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email.trim().toLowerCase() })
    });
    const options = await challengeRes.json();

    if (!options || !options.success) {
      if (options && options.no_credentials) {
        throw new Error(options.error);
      }
      throw new Error((options && options.error) ? options.error : 'Failed to initialize biometric login.');
    }

    // 2. Format options for navigator.credentials.get()
    const allowCredentials = (options.allowCredentials || []).map(c => ({
      type: c.type || 'public-key',
      id: this.base64urlToBuffer(c.id)
    }));

    const publicKey = {
      challenge: this.base64urlToBuffer(options.challenge),
      rpId: options.rpId,
      allowCredentials: allowCredentials,
      userVerification: options.userVerification || 'required',
      timeout: options.timeout || 60000
    };

    // 3. Prompt OS native biometric
    const assertion = await navigator.credentials.get({ publicKey });
    if (!assertion) {
      throw new Error('Biometric verification cancelled.');
    }

    // 4. Send assertion to server for cryptographic verification
    const payload = {
      action: 'verify_assertion',
      credential_id: assertion.id,
      clientDataJSON: this.bufferToBase64url(assertion.response.clientDataJSON),
      authenticatorData: this.bufferToBase64url(assertion.response.authenticatorData),
      signature: this.bufferToBase64url(assertion.response.signature)
    };

    const verifyRes = await fetch('api/biometric_auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await verifyRes.json();

    if (!result || !result.success) {
      throw new Error((result && result.error) ? result.error : 'Biometric verification failed.');
    }

    return result;
  },

  // Alias for compatibility
  async isAvailable() {
    return await this.isPlatformAuthenticatorAvailable();
  }
};

window.BiometricAuth = BiometricAuth;
window.BiometricClient = BiometricAuth;
