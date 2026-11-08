import * as Google from 'expo-auth-session/providers/google';
import * as WebBrowser from 'expo-web-browser';
import { useEffect } from 'react';

// Required: completes the OAuth session redirect in browser
WebBrowser.maybeCompleteAuthSession();

/**
 * useGoogleAuth — wraps expo-auth-session Google provider.
 *
 * Usage:
 *   const { request, response, promptAsync } = useGoogleAuth();
 *   // call promptAsync() to open Google consent screen
 *   // watch `response` for auth result
 */
const GOOGLE_CLIENT_ID =
  process.env.EXPO_PUBLIC_GOOGLE_CLIENT_ID ||
  '1047729043749-vb1i0ftrk9nhivt1lm9racpkij8q8u1p.apps.googleusercontent.com';

export function useGoogleAuth() {
  const [request, response, promptAsync] = Google.useAuthRequest({
    clientId: GOOGLE_CLIENT_ID,
    webClientId: process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID || GOOGLE_CLIENT_ID,
    androidClientId: process.env.EXPO_PUBLIC_GOOGLE_ANDROID_CLIENT_ID || GOOGLE_CLIENT_ID,
    iosClientId: process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID || GOOGLE_CLIENT_ID,
    scopes: ['openid', 'profile', 'email'],
  });

  return { request, response, promptAsync, isReady: !!request };
}

