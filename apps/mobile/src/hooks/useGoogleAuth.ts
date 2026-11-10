import * as WebBrowser from 'expo-web-browser';
import { makeRedirectUri } from 'expo-auth-session';
import { useState, useEffect } from 'react';

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
  const [loading, setLoading] = useState(false);
  const [response, setResponse] = useState<any>(null);

  const promptAsync = async () => {
    try {
      setLoading(true);
      // Automatically generates exp://... in Expo Go and nexusbids://oauth in native APK builds
      const returnUrl = makeRedirectUri({ scheme: 'nexusbids', path: 'oauth' });
      const apiUrl = process.env.EXPO_PUBLIC_API_URL || 'https://nexusbids.vercel.app/api/v1';
      const authUrl = `${apiUrl}/auth/google?mobile=1&redirect_uri=${encodeURIComponent(returnUrl)}`;

      const result = await WebBrowser.openAuthSessionAsync(authUrl, returnUrl);

      if (result.type === 'success' && result.url) {
        const urlStr = result.url;
        const queryIdx = urlStr.indexOf('?');
        const queryStr = queryIdx !== -1 ? urlStr.substring(queryIdx + 1) : '';
        const params: Record<string, string> = {};
        for (const pair of queryStr.split('&')) {
          const [k, v] = pair.split('=');
          if (k) params[decodeURIComponent(k)] = decodeURIComponent(v || '');
        }

        const accessToken = params.accessToken || params.access_token;
        const refreshToken = params.refreshToken || params.refresh_token;

        if (accessToken) {
          const authObj = {
            type: 'success',
            authentication: { accessToken, refreshToken },
            params: { access_token: accessToken, refresh_token: refreshToken },
          };
          setResponse(authObj);
          return authObj;
        } else if (params.error) {
          const errObj = { type: 'error', error: { message: params.error } };
          setResponse(errObj);
          return errObj;
        }
      }

      setResponse({ type: result.type });
      return result;
    } catch (err: any) {
      setResponse({ type: 'error', error: err });
      throw err;
    } finally {
      setLoading(false);
    }
  };

  return { request: true, response, promptAsync, isReady: true, loading };
}

