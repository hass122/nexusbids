import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  Modal,
  TextInput,
  TouchableOpacity,
  ActivityIndicator,
  Alert,
  ScrollView,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../../theme/colors';
import { useAuth } from '../../context/AuthContext';
import { MobileApi } from '../../api/client';
import { NotificationService } from '../../services/notificationService';
import { useGoogleAuth } from '../../hooks/useGoogleAuth';

export type AuthMode = 'login' | 'register' | 'forgot' | 'reset' | 'verify_email' | '2fa';

interface AuthModalProps {
  visible: boolean;
  onClose: () => void;
  initialMode?: AuthMode;
}

export const AuthModal: React.FC<AuthModalProps> = ({
  visible,
  onClose,
  initialMode = 'login',
}) => {
  const { login, register, googleLogin, verify2FA, verifyEmail } = useAuth();
  const [mode, setMode] = useState<AuthMode>(initialMode);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');

  // Form Fields
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fullName, setFullName] = useState('');
  const [phone, setPhone] = useState('');
  const [role, setRole] = useState<'user' | 'seller'>('user');
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [twoFactorUserId, setTwoFactorUserId] = useState<number | null>(null);
  const [resetToken, setResetToken] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [emailVerifyToken, setEmailVerifyToken] = useState('');

  // Google OAuth via expo-auth-session
  const { request: googleRequest, response: googleResponse, promptAsync: promptGoogleAsync } = useGoogleAuth();

  // Automatically handle the OAuth response when user returns from Google browser
  useEffect(() => {
    if (googleResponse?.type === 'success') {
      const token =
        googleResponse.authentication?.accessToken ||
        googleResponse.authentication?.idToken ||
        (googleResponse.params as any)?.access_token ||
        (googleResponse.params as any)?.id_token ||
        (googleResponse.params as any)?.code;

      if (token) {
        setLoading(true);
        setErrorMessage('');
        googleLogin(token)
          .then(() => {
            clearForm();
            onClose();
          })
          .catch((err: any) => {
            setErrorMessage(err.message || 'Google sign-in failed. Please try again.');
          })
          .finally(() => setLoading(false));
      } else {
        setErrorMessage('Google authentication completed, but no token was returned.');
      }
    } else if (googleResponse?.type === 'error') {
      const desc = (googleResponse.params as any)?.error_description || googleResponse.error?.message;
      setErrorMessage(desc || 'Google sign-in was cancelled or failed.');
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [googleResponse]);

  const handleGooglePress = async () => {
    setErrorMessage('');
    try {
      if (promptGoogleAsync) {
        await promptGoogleAsync();
      } else {
        setErrorMessage('Google authentication is not ready yet. Please try again in a moment.');
      }
    } catch (e: any) {
      setErrorMessage(e.message || 'Could not launch Google authentication.');
    }
  };

  const clearForm = () => {
    setErrorMessage('');
    setEmail('');
    setPassword('');
    setFullName('');
    setPhone('');
    setTwoFactorCode('');
    setResetToken('');
    setNewPassword('');
    setEmailVerifyToken('');
  };

  const handleLogin = async () => {
    setErrorMessage('');
    if (!email.trim() || !password) {
      setErrorMessage('Please enter both your email address and password.');
      return;
    }
    setLoading(true);
    try {
      const res = await login(email.trim(), password);
      if (res.requires2FA && res.userId) {
        setTwoFactorUserId(res.userId);
        setMode('2fa');
        return;
      }
      NotificationService.notify({
        type: 'messages',
        title: '👋 Welcome back!',
        message: 'Successfully signed into your NexusBids account.',
      });
      clearForm();
      onClose();
    } catch (err: any) {
      setErrorMessage(err.message || 'Invalid email or password.');
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async () => {
    setErrorMessage('');
    if (!fullName.trim() || !email.trim() || !password) {
      setErrorMessage('Full name, email and password are required.');
      return;
    }
    if (password.length < 8) {
      setErrorMessage('Password must be at least 8 characters long.');
      return;
    }
    setLoading(true);
    try {
      await register({
        full_name: fullName.trim(),
        email: email.trim(),
        password,
        phone: phone.trim() || null,
        role,
      });
      Alert.alert(
        'Account Created! 🛡️',
        'Your account has been registered. Please check your email for the verification link/code.'
      );
      setMode('verify_email');
    } catch (err: any) {
      setErrorMessage(err.message || 'Could not create account. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyEmail = async () => {
    setErrorMessage('');
    if (!emailVerifyToken.trim()) {
      setErrorMessage('Please enter the verification token received in your email.');
      return;
    }
    setLoading(true);
    try {
      await verifyEmail(emailVerifyToken.trim());
      Alert.alert('Email Verified! ✅', 'Your email has been verified. You can now sign in.');
      setMode('login');
    } catch (err: any) {
      setErrorMessage(err.message || 'Invalid or expired verification token.');
    } finally {
      setLoading(false);
    }
  };

  const handle2FA = async () => {
    setErrorMessage('');
    if (!twoFactorCode.trim() || twoFactorCode.trim().length !== 6 || !twoFactorUserId) {
      setErrorMessage('Please enter the 6-digit numeric authenticator code.');
      return;
    }
    setLoading(true);
    try {
      await verify2FA(twoFactorUserId, twoFactorCode.trim());
      clearForm();
      onClose();
    } catch (err: any) {
      setErrorMessage(err.message || 'Invalid two-factor code.');
    } finally {
      setLoading(false);
    }
  };

  const handleForgotPassword = async () => {
    setErrorMessage('');
    if (!email.trim()) {
      setErrorMessage('Please enter your registered email address.');
      return;
    }
    setLoading(true);
    try {
      await MobileApi.forgotPassword(email.trim());
      Alert.alert(
        'Reset Token Dispatched',
        'If an account exists, a password reset token has been sent to your email.'
      );
      setMode('reset');
    } catch (err: any) {
      setErrorMessage(err.message || 'Failed to request password reset.');
    } finally {
      setLoading(false);
    }
  };

  const handleResetPassword = async () => {
    setErrorMessage('');
    if (!resetToken.trim() || !newPassword) {
      setErrorMessage('Please enter both the reset token and your new password.');
      return;
    }
    if (newPassword.length < 8) {
      setErrorMessage('New password must be at least 8 characters long.');
      return;
    }
    setLoading(true);
    try {
      await MobileApi.resetPassword({ token: resetToken.trim(), password: newPassword });
      Alert.alert('Password Updated! 🔒', 'Your password has been reset. You may now sign in.');
      setMode('login');
    } catch (err: any) {
      setErrorMessage(err.message || 'Failed to reset password. Check your token.');
    } finally {
      setLoading(false);
    }
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View>
              <Text style={styles.headerTitle}>
                {mode === 'login' && 'Sign In to NexusBids'}
                {mode === 'register' && 'Create Your Account'}
                {mode === 'forgot' && 'Reset Password'}
                {mode === 'reset' && 'Set New Password'}
                {mode === 'verify_email' && 'Verify Email Address'}
                {mode === '2fa' && 'Two-Factor Challenge'}
              </Text>
              <Text style={styles.headerSubtitle}>
                {mode === 'login' && 'Access Pakistan’s trusted live auctions & escrow'}
                {mode === 'register' && 'Join thousands of verified Pakistani buyers & sellers'}
                {mode === 'forgot' && 'Enter your email to receive recovery instructions'}
                {mode === 'reset' && 'Enter the token from your email and chosen password'}
                {mode === 'verify_email' && 'Paste token from verification email to activate'}
                {mode === '2fa' && 'Enter the 6-digit code from Google Authenticator'}
              </Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          {errorMessage ? (
            <View style={styles.errorBox}>
              <Ionicons name="alert-circle-outline" size={15} color={Colors.danger} style={{ marginRight: 6 }} />
              <Text style={styles.errorText}>{errorMessage}</Text>
            </View>
          ) : null}

          <ScrollView showsVerticalScrollIndicator={false} style={styles.body}>
            {/* LOGIN FORM */}
            {mode === 'login' && (
              <>
                <Text style={styles.label}>Email Address</Text>
                <TextInput
                  style={styles.input}
                  placeholder="your.email@example.com"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="email-address"
                  autoCapitalize="none"
                  value={email}
                  onChangeText={setEmail}
                />

                <Text style={styles.label}>Password</Text>
                <TextInput
                  style={styles.input}
                  placeholder="••••••••••••"
                  placeholderTextColor={Colors.textDim}
                  secureTextEntry
                  value={password}
                  onChangeText={setPassword}
                />

                <TouchableOpacity
                  style={styles.forgotLink}
                  onPress={() => {
                    setErrorMessage('');
                    setMode('forgot');
                  }}
                >
                  <Text style={styles.forgotText}>Forgot password?</Text>
                </TouchableOpacity>

                <TouchableOpacity
                  style={styles.primaryBtn}
                  onPress={handleLogin}
                  disabled={loading}
                >
                  {loading ? (
                    <ActivityIndicator color="#ffffff" />
                  ) : (
                    <Text style={styles.primaryBtnText}>Sign In →</Text>
                  )}
                </TouchableOpacity>

                <View style={styles.switchRow}>
                  <Text style={styles.switchPrompt}>New to NexusBids? </Text>
                  <TouchableOpacity
                    onPress={() => {
                      setErrorMessage('');
                      setMode('register');
                    }}
                  >
                    <Text style={styles.switchAction}>Create Account</Text>
                  </TouchableOpacity>
                </View>

                {/* Google OAuth Divider */}
                <View style={styles.dividerRow}>
                  <View style={styles.dividerLine} />
                  <Text style={styles.dividerText}>or continue with</Text>
                  <View style={styles.dividerLine} />
                </View>

                {/* Google Sign-In Button */}
                <TouchableOpacity
                  style={styles.googleBtn}
                  onPress={handleGooglePress}
                  disabled={loading}
                  activeOpacity={0.8}
                >
                  {loading ? (
                    <ActivityIndicator color={Colors.text} size="small" />
                  ) : (
                    <View style={styles.googleBtnInner}>
                      <Ionicons name="logo-google" size={18} color="#EA4335" />
                      <Text style={styles.googleBtnText}>Continue with Google</Text>
                    </View>
                  )}
                </TouchableOpacity>

                <TouchableOpacity
                  style={styles.verifyLinkWrap}
                  onPress={() => {
                    setErrorMessage('');
                    setMode('verify_email');
                  }}
                >
                  <Text style={styles.verifyLinkText}>Have a verification token? Verify Email</Text>
                </TouchableOpacity>
              </>
            )}

            {/* REGISTER FORM */}
            {mode === 'register' && (
              <>
                <Text style={styles.label}>Full Name (As on CNIC)</Text>
                <TextInput
                  style={styles.input}
                  placeholder="e.g. Muhammad Ali Khan"
                  placeholderTextColor={Colors.textDim}
                  value={fullName}
                  onChangeText={setFullName}
                />

                <Text style={styles.label}>Email Address</Text>
                <TextInput
                  style={styles.input}
                  placeholder="your.email@example.com"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="email-address"
                  autoCapitalize="none"
                  value={email}
                  onChangeText={setEmail}
                />

                <Text style={styles.label}>Mobile Number (Pakistan)</Text>
                <TextInput
                  style={styles.input}
                  placeholder="0300-1234567"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="phone-pad"
                  value={phone}
                  onChangeText={setPhone}
                />

                <Text style={styles.label}>Password (Min. 8 characters)</Text>
                <TextInput
                  style={styles.input}
                  placeholder="Create secure password"
                  placeholderTextColor={Colors.textDim}
                  secureTextEntry
                  value={password}
                  onChangeText={setPassword}
                />

                <Text style={styles.label}>Account Role</Text>
                <View style={styles.roleToggle}>
                  <TouchableOpacity
                    style={[styles.roleBtn, role === 'user' && styles.roleBtnActive]}
                    onPress={() => setRole('user')}
                  >
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                      <Ionicons
                        name="cart-outline"
                        size={15}
                        color={role === 'user' ? '#ffffff' : Colors.textMuted}
                      />
                      <Text style={[styles.roleBtnText, role === 'user' && styles.roleBtnTextActive]}>
                        Buyer
                      </Text>
                    </View>
                  </TouchableOpacity>
                  <TouchableOpacity
                    style={[styles.roleBtn, role === 'seller' && styles.roleBtnActive]}
                    onPress={() => setRole('seller')}
                  >
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                      <Ionicons
                        name="briefcase-outline"
                        size={15}
                        color={role === 'seller' ? '#ffffff' : Colors.textMuted}
                      />
                      <Text
                        style={[styles.roleBtnText, role === 'seller' && styles.roleBtnTextActive]}
                      >
                        Seller Studio
                      </Text>
                    </View>
                  </TouchableOpacity>
                </View>

                <TouchableOpacity
                  style={styles.primaryBtn}
                  onPress={handleRegister}
                  disabled={loading}
                >
                  {loading ? (
                    <ActivityIndicator color="#ffffff" />
                  ) : (
                    <Text style={styles.primaryBtnText}>Register Account →</Text>
                  )}
                </TouchableOpacity>

                <View style={styles.switchRow}>
                  <Text style={styles.switchPrompt}>Already have an account? </Text>
                  <TouchableOpacity
                    onPress={() => {
                      setErrorMessage('');
                      setMode('login');
                    }}
                  >
                    <Text style={styles.switchAction}>Sign In</Text>
                  </TouchableOpacity>
                </View>

                {/* Google OAuth Divider */}
                <View style={styles.dividerRow}>
                  <View style={styles.dividerLine} />
                  <Text style={styles.dividerText}>or sign up with</Text>
                  <View style={styles.dividerLine} />
                </View>

                {/* Google Sign-Up Button */}
                <TouchableOpacity
                  style={styles.googleBtn}
                  onPress={handleGooglePress}
                  disabled={loading}
                  activeOpacity={0.8}
                >
                  {loading ? (
                    <ActivityIndicator color={Colors.text} size="small" />
                  ) : (
                    <View style={styles.googleBtnInner}>
                      <Ionicons name="logo-google" size={18} color="#EA4335" />
                      <Text style={styles.googleBtnText}>Continue with Google</Text>
                    </View>
                  )}
                </TouchableOpacity>
              </>
            )}

            {/* VERIFY EMAIL FORM */}
            {mode === 'verify_email' && (
              <>
                <Text style={styles.label}>Email Verification Token</Text>
                <TextInput
                  style={styles.input}
                  placeholder="Paste token received in email"
                  placeholderTextColor={Colors.textDim}
                  value={emailVerifyToken}
                  onChangeText={setEmailVerifyToken}
                />

                <TouchableOpacity
                  style={styles.primaryBtn}
                  onPress={handleVerifyEmail}
                  disabled={loading}
                >
                  {loading ? (
                    <ActivityIndicator color="#ffffff" />
                  ) : (
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                      <Ionicons name="checkmark-circle-outline" size={16} color="#ffffff" />
                      <Text style={styles.primaryBtnText}>Confirm Verification</Text>
                    </View>
                  )}
                </TouchableOpacity>

                <TouchableOpacity
                  style={styles.backBtn}
                  onPress={() => {
                    setErrorMessage('');
                    setMode('login');
                  }}
                >
                  <Text style={styles.backBtnText}>← Back to Sign In</Text>
                </TouchableOpacity>
              </>
            )}

            {/* FORGOT PASSWORD FORM */}
            {mode === 'forgot' && (
              <>
                <Text style={styles.label}>Your Registered Email</Text>
                <TextInput
                  style={styles.input}
                  placeholder="your.email@example.com"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="email-address"
                  autoCapitalize="none"
                  value={email}
                  onChangeText={setEmail}
                />

                <TouchableOpacity
                  style={styles.primaryBtn}
                  onPress={handleForgotPassword}
                  disabled={loading}
                >
                  {loading ? (
                    <ActivityIndicator color="#ffffff" />
                  ) : (
                    <Text style={styles.primaryBtnText}>Send Recovery Token →</Text>
                  )}
                </TouchableOpacity>

                <TouchableOpacity
                  style={styles.backBtn}
                  onPress={() => {
                    setErrorMessage('');
                    setMode('login');
                  }}
                >
                  <Text style={styles.backBtnText}>← Back to Sign In</Text>
                </TouchableOpacity>
              </>
            )}

            {/* RESET PASSWORD FORM */}
            {mode === 'reset' && (
              <>
                <Text style={styles.label}>Reset Token (from email)</Text>
                <TextInput
                  style={styles.input}
                  placeholder="Token string"
                  placeholderTextColor={Colors.textDim}
                  value={resetToken}
                  onChangeText={setResetToken}
                />

                <Text style={styles.label}>New Password</Text>
                <TextInput
                  style={styles.input}
                  placeholder="Minimum 8 characters"
                  placeholderTextColor={Colors.textDim}
                  secureTextEntry
                  value={newPassword}
                  onChangeText={setNewPassword}
                />

                <TouchableOpacity
                  style={styles.primaryBtn}
                  onPress={handleResetPassword}
                  disabled={loading}
                >
                  {loading ? (
                    <ActivityIndicator color="#ffffff" />
                  ) : (
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                      <Ionicons name="lock-closed-outline" size={15} color="#ffffff" />
                      <Text style={styles.primaryBtnText}>Update Password</Text>
                    </View>
                  )}
                </TouchableOpacity>

                <TouchableOpacity
                  style={styles.backBtn}
                  onPress={() => {
                    setErrorMessage('');
                    setMode('login');
                  }}
                >
                  <Text style={styles.backBtnText}>← Back to Sign In</Text>
                </TouchableOpacity>
              </>
            )}

            {/* 2FA CODE FORM */}
            {mode === '2fa' && (
              <>
                <Text style={styles.label}>Authenticator 6-Digit Code</Text>
                <TextInput
                  style={[styles.input, styles.otpInput]}
                  placeholder="000000"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="numeric"
                  maxLength={6}
                  value={twoFactorCode}
                  onChangeText={setTwoFactorCode}
                />

                <TouchableOpacity
                  style={styles.primaryBtn}
                  onPress={handle2FA}
                  disabled={loading}
                >
                  {loading ? (
                    <ActivityIndicator color="#ffffff" />
                  ) : (
                    <Text style={styles.primaryBtnText}>Verify 2FA Code →</Text>
                  )}
                </TouchableOpacity>

                <TouchableOpacity
                  style={styles.backBtn}
                  onPress={() => {
                    setErrorMessage('');
                    setMode('login');
                  }}
                >
                  <Text style={styles.backBtnText}>← Cancel and Return</Text>
                </TouchableOpacity>
              </>
            )}
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.85)',
    justifyContent: 'flex-end',
  },
  sheet: {
    backgroundColor: Colors.surface,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    maxHeight: '90%',
    padding: 20,
    borderTopWidth: 1,
    borderColor: Colors.border,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    marginBottom: 16,
  },
  headerTitle: {
    fontSize: 18,
    fontWeight: '900',
    color: Colors.text,
    marginBottom: 4,
  },
  headerSubtitle: {
    fontSize: 11,
    color: Colors.textMuted,
    maxWidth: 270,
  },
  closeBtn: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: Colors.surfaceLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeText: {
    fontSize: 15,
    fontWeight: '700',
    color: Colors.textDim,
  },
  errorBox: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: 'rgba(239, 68, 68, 0.15)',
    borderWidth: 1,
    borderColor: Colors.danger,
    borderRadius: 10,
    padding: 10,
    marginBottom: 14,
  },
  errorText: {
    color: Colors.danger,
    fontSize: 11,
    fontWeight: '600',
  },
  body: {
    marginBottom: 20,
  },
  label: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.textMuted,
    marginBottom: 6,
    textTransform: 'uppercase',
  },
  input: {
    backgroundColor: Colors.card,
    borderWidth: 1,
    borderColor: Colors.border,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 14,
    color: Colors.text,
    marginBottom: 14,
  },
  otpInput: {
    textAlign: 'center',
    fontSize: 24,
    fontWeight: '900',
    letterSpacing: 8,
  },
  forgotLink: {
    alignSelf: 'flex-end',
    marginBottom: 16,
  },
  forgotText: {
    fontSize: 11,
    color: Colors.primaryLight,
    fontWeight: '700',
  },
  primaryBtn: {
    backgroundColor: Colors.primary,
    paddingVertical: 14,
    borderRadius: 12,
    alignItems: 'center',
    marginBottom: 14,
  },
  primaryBtnText: {
    fontSize: 14,
    fontWeight: '800',
    color: '#ffffff',
  },
  switchRow: {
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    marginTop: 6,
    marginBottom: 10,
  },
  switchPrompt: {
    fontSize: 12,
    color: Colors.textMuted,
  },
  switchAction: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.primaryLight,
  },
  verifyLinkWrap: {
    alignItems: 'center',
    marginTop: 6,
  },
  verifyLinkText: {
    fontSize: 11,
    color: Colors.textDim,
    textDecorationLine: 'underline',
  },
  backBtn: {
    alignItems: 'center',
    paddingVertical: 10,
  },
  backBtnText: {
    fontSize: 12,
    color: Colors.textMuted,
    fontWeight: '700',
  },
  roleToggle: {
    flexDirection: 'row',
    backgroundColor: Colors.card,
    borderRadius: 12,
    padding: 4,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  roleBtn: {
    flex: 1,
    paddingVertical: 10,
    alignItems: 'center',
    borderRadius: 8,
  },
  roleBtnActive: {
    backgroundColor: Colors.primary,
  },
  roleBtnText: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.textMuted,
  },
  roleBtnTextActive: {
    color: '#ffffff',
  },
  // ── Google OAuth ────────────────────────────────────────────────
  dividerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginVertical: 14,
    gap: 8,
  },
  dividerLine: {
    flex: 1,
    height: 1,
    backgroundColor: Colors.border,
  },
  dividerText: {
    fontSize: 11,
    color: Colors.textDim,
    fontWeight: '600',
    letterSpacing: 0.5,
  },
  googleBtn: {
    backgroundColor: Colors.card,
    borderWidth: 1,
    borderColor: Colors.border,
    borderRadius: 12,
    paddingVertical: 13,
    paddingHorizontal: 16,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 10,
  },
  googleBtnInner: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  googleBtnText: {
    fontSize: 14,
    fontWeight: '700',
    color: Colors.text,
  },
});
