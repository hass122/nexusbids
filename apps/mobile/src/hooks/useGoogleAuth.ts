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
export function useGoogleAuth() {
  const [request, response, promptAsync] = Google.useAuthRequest({
    // Web client ID is used for Expo Go and all managed workflow builds.
    // Add androidClientId / iosClientId for standalone native builds.
    clientId: process.env.EXPO_PUBLIC_GOOGLE_CLIENT_ID,
    // Scopes: openid is required for id_token; profile + email for user info
    scopes: ['openid', 'profile', 'email'],
  });

  return { request, response, promptAsync };
}

