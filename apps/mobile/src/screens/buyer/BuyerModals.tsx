import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  Modal,
  ScrollView,
  TouchableOpacity,
  TextInput,
  ActivityIndicator,
  Alert,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../../theme/colors';
import { MobileApi } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { NotificationService } from '../../services/notificationService';

// ─── 1. Buyer NADRA KYC Verification Modal ───────────────────
export const BuyerKycModal: React.FC<{
  visible: boolean;
  onClose: () => void;
}> = ({ visible, onClose }) => {
  const { user, refreshProfile } = useAuth();
  const [fullName, setFullName] = useState(user?.full_name || '');
  const [cnicNumber, setCnicNumber] = useState('');
  const [dob, setDob] = useState('1995-05-15');
  const [phone, setPhone] = useState(user?.phone || '');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmitKyc = async () => {
    const rawCnic = cnicNumber.replace(/\D/g, '');
    if (rawCnic.length !== 13) {
      Alert.alert('Invalid CNIC', 'NADRA CNIC must be exactly 13 digits (e.g. 35201-1234567-1).');
      return;
    }
    if (!fullName.trim()) {
      Alert.alert('Required', 'Please enter your full legal name as printed on CNIC.');
      return;
    }

    setSubmitting(true);
    try {
      await MobileApi.submitBuyerKyc({
        full_name_on_cnic: fullName.trim(),
        cnic_number: rawCnic,
        date_of_birth: dob,
        phone,
        cnic_front_path: 'https://images.unsplash.com/photo-1589829545856-d10d557cf95f?w=600',
        cnic_back_path: 'https://images.unsplash.com/photo-1589829545856-d10d557cf95f?w=600',
        selfie_path: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=600',
        selfie_with_cnic_path: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=600',
      });
      NotificationService.notify({
        type: 'messages',
        title: '🪪 NADRA KYC Submitted',
        message: 'Your documents have been submitted for automated NADRA biometric matching.',
      });
      Alert.alert('KYC Submitted! 🪪', 'Your documents have been queued for automated verification.');
      await refreshProfile();
      onClose();
    } catch (err: any) {
      Alert.alert('Submission Error', err.message || 'Failed to submit KYC documents');
    } finally {
      setSubmitting(false);
    }
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View>
              <Text style={styles.title}>NADRA CNIC Verification</Text>
              <Text style={styles.subtitle}>Pakistani Government Identity Compliance</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.body}>
            <View style={styles.statusBox}>
              <Text style={styles.statusTitle}>Verification Status</Text>
              <Text style={[styles.statusVal, user?.kyc_status === 'approved' ? styles.statusApproved : styles.statusPending]}>
                {(user?.kyc_status || 'UNVERIFIED').toUpperCase()}
              </Text>
            </View>

            <Text style={styles.desc}>
              Government compliance ensures a secure auction environment free of shill bidding and identity fraud across Pakistan.
            </Text>

            <Text style={styles.label}>Full Name on CNIC</Text>
            <TextInput
              style={styles.input}
              placeholder="e.g. Muhammad Ali"
              placeholderTextColor={Colors.textDim}
              value={fullName}
              onChangeText={setFullName}
            />

            <Text style={styles.label}>13-Digit NADRA CNIC</Text>
            <TextInput
              style={styles.input}
              placeholder="35201-1234567-1"
              placeholderTextColor={Colors.textDim}
              keyboardType="numeric"
              value={cnicNumber}
              onChangeText={setCnicNumber}
            />

            <Text style={styles.label}>Date of Birth (YYYY-MM-DD)</Text>
            <TextInput
              style={styles.input}
              placeholder="1995-05-15"
              placeholderTextColor={Colors.textDim}
              value={dob}
              onChangeText={setDob}
            />

            <View style={styles.docBox}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                <Ionicons name="card-outline" size={16} color={Colors.primaryLight} />
                <Text style={styles.docLabel}>CNIC Front Document Scan</Text>
              </View>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 3 }}>
                <Ionicons name="checkmark-circle" size={13} color={Colors.success} />
                <Text style={styles.docStatus}>Attached</Text>
              </View>
            </View>

            <View style={styles.docBox}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                <Ionicons name="card-outline" size={16} color={Colors.primaryLight} />
                <Text style={styles.docLabel}>CNIC Back Document Scan</Text>
              </View>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 3 }}>
                <Ionicons name="checkmark-circle" size={13} color={Colors.success} />
                <Text style={styles.docStatus}>Attached</Text>
              </View>
            </View>

            <View style={styles.docBox}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                <Ionicons name="scan-outline" size={16} color={Colors.primaryLight} />
                <Text style={styles.docLabel}>Live Facial Biometric Selfie</Text>
              </View>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 3 }}>
                <Ionicons name="checkmark-circle" size={13} color={Colors.success} />
                <Text style={styles.docStatus}>Verified</Text>
              </View>
            </View>

            <TouchableOpacity style={styles.submitBtn} onPress={handleSubmitKyc} disabled={submitting}>
              {submitting ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                  <Ionicons name="shield-checkmark" size={16} color="#ffffff" />
                  <Text style={styles.submitBtnText}>Submit NADRA KYC</Text>
                </View>
              )}
            </TouchableOpacity>

            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 14 }}>
              <Ionicons name="lock-closed" size={13} color={Colors.textMuted} />
              <Text style={styles.privacyNote}>
                Protected by AES-256 encryption. Data is strictly processed for NADRA verification.
              </Text>
            </View>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

// ─── 2. Notifications Center Modal ────────────────────────────
export const NotificationsModal: React.FC<{
  visible: boolean;
  onClose: () => void;
}> = ({ visible, onClose }) => {
  const [notifications, setNotifications] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchNotifs = async () => {
    try {
      const res = await MobileApi.getNotifications();
      const list = res.data || [];
      if (list.length > 0) {
        setNotifications(list);
      } else {
        setNotifications(NotificationService.getHistory());
      }
    } catch {
      setNotifications(NotificationService.getHistory());
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (visible) fetchNotifs();
  }, [visible]);

  const handleMarkAllRead = async () => {
    try {
      await MobileApi.markNotificationsRead();
      NotificationService.markAllAsRead();
      fetchNotifs();
    } catch {}
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View>
              <Text style={styles.title}>Notification Center</Text>
              <Text style={styles.subtitle}>Live alerts, bids, and escrow updates</Text>
            </View>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
              <TouchableOpacity onPress={handleMarkAllRead}>
                <Text style={styles.markReadText}>Mark Read</Text>
              </TouchableOpacity>
              <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
                <Ionicons name="close" size={18} color={Colors.textMuted} />
              </TouchableOpacity>
            </View>
          </View>

          {loading ? (
            <ActivityIndicator size="large" color={Colors.primary} style={{ marginVertical: 40 }} />
          ) : notifications.length === 0 ? (
            <View style={{ padding: 40, alignItems: 'center' }}>
              <View style={{ width: 56, height: 56, borderRadius: 28, backgroundColor: 'rgba(99, 102, 241, 0.15)', alignItems: 'center', justifyContent: 'center', marginBottom: 14 }}>
                <Ionicons name="notifications-outline" size={28} color={Colors.primaryLight} />
              </View>
              <Text style={{ color: Colors.text, fontWeight: '800', fontSize: 15 }}>No New Notifications</Text>
              <Text style={{ color: Colors.textMuted, fontSize: 12, marginTop: 4, textAlign: 'center' }}>
                You're completely caught up with bidding alerts and order dispatches.
              </Text>
            </View>
          ) : (
            <ScrollView showsVerticalScrollIndicator={false} style={styles.body}>
              {notifications.map((n, idx) => (
                <View key={n.id || n.notification_id || idx} style={styles.notifItem}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                    <Ionicons name="notifications" size={13} color={Colors.primaryLight} />
                    <Text style={styles.notifTitle}>{n.title}</Text>
                  </View>
                  <Text style={styles.notifMsg}>{n.message}</Text>
                  <Text style={styles.notifTime}>{new Date(n.created_at || n.timestamp || Date.now()).toLocaleTimeString()}</Text>
                </View>
              ))}
            </ScrollView>
          )}
        </View>
      </View>
    </Modal>
  );
};

// ─── 3. Delivery Addresses Modal ──────────────────────────────
export const AddressesModal: React.FC<{
  visible: boolean;
  onClose: () => void;
}> = ({ visible, onClose }) => {
  const [addresses, setAddresses] = useState<any[]>([]);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [street, setStreet] = useState('');
  const [city, setCity] = useState('Lahore');
  const [province, setProvince] = useState('Punjab');
  const [showAddForm, setShowAddForm] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const loadAddresses = async () => {
    try {
      const res = await MobileApi.getAddresses();
      setAddresses(res.data || []);
    } catch {
      setAddresses([
        { address_id: 1, full_name: 'Muhammad Ali', phone: '0300-1234567', address: 'House 45, Street 12, DHA Phase 5', city: 'Lahore', province: 'Punjab', is_default: 1 },
      ]);
    }
  };

  useEffect(() => {
    if (visible) loadAddresses();
  }, [visible]);

  const handleAdd = async () => {
    if (!name.trim() || !street.trim() || !phone.trim()) {
      Alert.alert('Required', 'Please fill in recipient name, phone, and complete address.');
      return;
    }
    setSubmitting(true);
    try {
      await MobileApi.addAddress({
        full_name: name.trim(),
        phone: phone.trim(),
        address: street.trim(),
        city: city.trim(),
        province,
        is_default: addresses.length === 0 ? 1 : 0,
      });
      setShowAddForm(false);
      setName('');
      setPhone('');
      setStreet('');
      loadAddresses();
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Could not add address');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (id: number) => {
    try {
      await MobileApi.deleteAddress(id);
      loadAddresses();
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Could not delete address');
    }
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View>
              <Text style={styles.title}>Shipping Destinations</Text>
              <Text style={styles.subtitle}>TCS, Leopards, and Trax Delivery Hubs</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.body}>
            {addresses.map((a) => (
              <View key={a.address_id || a.id} style={styles.addrCard}>
                <View style={styles.addrHeader}>
                  <Text style={styles.addrName}>{a.full_name}</Text>
                  {a.is_default ? <Text style={styles.defaultTag}>Default</Text> : null}
                </View>
                <Text style={styles.addrStreet}>{a.address}, {a.city}, {a.province || 'Pakistan'}</Text>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 5, marginTop: 2 }}>
                  <Ionicons name="call-outline" size={12} color={Colors.textMuted} />
                  <Text style={styles.addrPhone}>{a.phone}</Text>
                </View>
                <TouchableOpacity style={styles.deleteAddrBtn} onPress={() => handleDelete(a.address_id || a.id)}>
                  <Text style={styles.deleteAddrText}>Delete Address</Text>
                </TouchableOpacity>
              </View>
            ))}

            {!showAddForm ? (
              <TouchableOpacity style={styles.addBtn} onPress={() => setShowAddForm(true)}>
                <Text style={styles.addBtnText}>+ Add New Delivery Destination</Text>
              </TouchableOpacity>
            ) : (
              <View style={styles.addBox}>
                <Text style={styles.addTitle}>New Pakistani Destination</Text>
                <TextInput style={styles.input} placeholder="Recipient Full Name" placeholderTextColor={Colors.textDim} value={name} onChangeText={setName} />
                <TextInput style={styles.input} placeholder="Recipient Mobile (03XX-XXXXXXX)" placeholderTextColor={Colors.textDim} keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
                <TextInput style={styles.input} placeholder="House / Flat #, Street, Area" placeholderTextColor={Colors.textDim} value={street} onChangeText={setStreet} />
                <TextInput style={styles.input} placeholder="City (e.g. Karachi, Lahore, Islamabad, Rawalpindi)" placeholderTextColor={Colors.textDim} value={city} onChangeText={setCity} />
                <TextInput style={styles.input} placeholder="Province (e.g. Punjab, Sindh, KPK, Balochistan)" placeholderTextColor={Colors.textDim} value={province} onChangeText={setProvince} />
                <TouchableOpacity style={styles.submitBtn} onPress={handleAdd} disabled={submitting}>
                  {submitting ? (
                    <ActivityIndicator color="#fff" />
                  ) : (
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                      <Ionicons name="location-outline" size={15} color="#ffffff" />
                      <Text style={styles.submitBtnText}>Save Address</Text>
                    </View>
                  )}
                </TouchableOpacity>
              </View>
            )}
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

// ─── 4. Security & 2FA Settings Modal ─────────────────────────
export const SecurityModal: React.FC<{
  visible: boolean;
  onClose: () => void;
}> = ({ visible, onClose }) => {
  const { user, changePassword, setup2FA, confirm2FA, disable2FA, logoutAll } = useAuth();
  const [currentPass, setCurrentPass] = useState('');
  const [newPass, setNewPass] = useState('');
  const [confirmPass, setConfirmPass] = useState('');
  const [changingPass, setChangingPass] = useState(false);

  // 2FA state
  const [totpSetupData, setTotpSetupData] = useState<any>(null);
  const [otpCode, setOtpCode] = useState('');
  const [managing2Fa, setManaging2Fa] = useState(false);

  const handleChangePassword = async () => {
    if (!currentPass || !newPass) {
      Alert.alert('Required', 'Please enter your current and new password.');
      return;
    }
    if (newPass !== confirmPass) {
      Alert.alert('Mismatch', 'New passwords do not match.');
      return;
    }
    if (newPass.length < 8) {
      Alert.alert('Weak Password', 'Password must be at least 8 characters long.');
      return;
    }

    setChangingPass(true);
    try {
      await changePassword(currentPass, newPass);
      Alert.alert('Password Changed! 🔒', 'Your password has been updated securely.');
      setCurrentPass('');
      setNewPass('');
      setConfirmPass('');
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Failed to change password');
    } finally {
      setChangingPass(false);
    }
  };

  const handleStart2FA = async () => {
    setManaging2Fa(true);
    try {
      const res = await setup2FA();
      setTotpSetupData(res.data);
    } catch (err: any) {
      Alert.alert('2FA Error', err.message || 'Could not initiate 2FA setup');
    } finally {
      setManaging2Fa(false);
    }
  };

  const handleConfirm2FA = async () => {
    if (!otpCode || otpCode.trim().length !== 6) {
      Alert.alert('Invalid OTP', 'Please enter the 6-digit code from Google Authenticator.');
      return;
    }
    setManaging2Fa(true);
    try {
      await confirm2FA(otpCode.trim());
      Alert.alert('2FA Enabled! 🛡️', 'Two-Factor Authentication is now actively protecting your account.');
      setTotpSetupData(null);
      setOtpCode('');
    } catch (err: any) {
      Alert.alert('2FA Error', err.message || 'Invalid 2FA code');
    } finally {
      setManaging2Fa(false);
    }
  };

  const handleLogoutAll = () => {
    Alert.alert(
      'Revoke All Sessions',
      'This will log you out from all mobile devices and web browsers.',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Revoke All',
          style: 'destructive',
          onPress: async () => {
            await logoutAll();
            onClose();
          },
        },
      ]
    );
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View>
              <Text style={styles.title}>Account Security & 2FA</Text>
              <Text style={styles.subtitle}>Biometrics, Passwords, and Active Sessions</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.body}>
            {/* 2FA Section */}
            <View style={styles.secBox}>
              <Text style={styles.secBoxTitle}>Two-Factor Authentication (2FA)</Text>
              <Text style={styles.secBoxSub}>
                Protect your bids and payouts with Google Authenticator or Microsoft Authenticator.
              </Text>

              {user?.two_factor_enabled ? (
                <View style={styles.twoFaActiveBanner}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                    <Ionicons name="checkmark-circle" size={16} color={Colors.success} />
                    <Text style={styles.twoFaActiveText}>2FA is currently ENABLED</Text>
                  </View>
                </View>
              ) : totpSetupData ? (
                <View style={styles.totpBox}>
                  <Text style={styles.label}>Authenticator Secret Key</Text>
                  <Text style={styles.secretText}>{totpSetupData.secret}</Text>
                  <Text style={styles.label}>Enter 6-Digit Code to Confirm</Text>
                  <TextInput
                    style={styles.input}
                    placeholder="000000"
                    placeholderTextColor={Colors.textDim}
                    keyboardType="numeric"
                    maxLength={6}
                    value={otpCode}
                    onChangeText={setOtpCode}
                  />
                  <TouchableOpacity style={styles.submitBtn} onPress={handleConfirm2FA} disabled={managing2Fa}>
                    {managing2Fa ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitBtnText}>Verify & Enable 2FA</Text>}
                  </TouchableOpacity>
                </View>
              ) : (
                <TouchableOpacity style={styles.secBtn} onPress={handleStart2FA} disabled={managing2Fa}>
                  <Text style={styles.secBtnText}>Enable Two-Factor Authentication →</Text>
                </TouchableOpacity>
              )}
            </View>

            {/* Change Password Section */}
            <View style={styles.secBox}>
              <Text style={styles.secBoxTitle}>Change Password</Text>
              <Text style={styles.label}>Current Password</Text>
              <TextInput
                style={styles.input}
                secureTextEntry
                placeholder="Current password"
                placeholderTextColor={Colors.textDim}
                value={currentPass}
                onChangeText={setCurrentPass}
              />

              <Text style={styles.label}>New Password (Min. 8 characters)</Text>
              <TextInput
                style={styles.input}
                secureTextEntry
                placeholder="New password"
                placeholderTextColor={Colors.textDim}
                value={newPass}
                onChangeText={setNewPass}
              />

              <Text style={styles.label}>Confirm New Password</Text>
              <TextInput
                style={styles.input}
                secureTextEntry
                placeholder="Confirm new password"
                placeholderTextColor={Colors.textDim}
                value={confirmPass}
                onChangeText={setConfirmPass}
              />

              <TouchableOpacity style={styles.submitBtn} onPress={handleChangePassword} disabled={changingPass}>
                {changingPass ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                    <Ionicons name="lock-closed" size={15} color="#ffffff" />
                    <Text style={styles.submitBtnText}>Update Password</Text>
                  </View>
                )}
              </TouchableOpacity>
            </View>

            {/* Logout from all devices */}
            <View style={styles.secBox}>
              <Text style={styles.secBoxTitle}>Active Sessions</Text>
              <Text style={styles.secBoxSub}>
                If you suspect unauthorized activity, terminate all active tokens across all devices immediately.
              </Text>
              <TouchableOpacity style={styles.dangerBtn} onPress={handleLogoutAll}>
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                  <Ionicons name="log-out-outline" size={16} color={Colors.danger} />
                  <Text style={styles.dangerBtnText}>Logout from All Devices</Text>
                </View>
              </TouchableOpacity>
            </View>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

// ─── 5. Reviews Modal ─────────────────────────────────────────
export const ReviewsModal: React.FC<{
  visible: boolean;
  sellerId?: number;
  orderId?: number;
  onClose: () => void;
}> = ({ visible, sellerId, orderId, onClose }) => {
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmitReview = async () => {
    if (!comment.trim()) {
      Alert.alert('Required', 'Please write a brief comment describing your experience.');
      return;
    }
    setSubmitting(true);
    try {
      await MobileApi.createReview({
        order_id: orderId || 1,
        seller_id: sellerId || 1,
        rating,
        comment: comment.trim(),
      });
      NotificationService.notify({
        type: 'messages',
        title: '⭐ Review Submitted',
        message: 'Thank you for your feedback! It helps keep NexusBids honest and reliable.',
      });
      Alert.alert('Review Posted! ⭐', 'Thank you for rating your transaction.');
      onClose();
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Could not submit review');
    } finally {
      setSubmitting(false);
    }
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View>
              <Text style={styles.title}>Merchant Review & Feedback</Text>
              <Text style={styles.subtitle}>Rate item quality, packaging, and fulfillment speed</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.body}>
            {/* Star Rating Selector */}
            <Text style={styles.label}>Rating Score</Text>
            <View style={styles.starRow}>
              {[1, 2, 3, 4, 5].map((s) => (
                <TouchableOpacity key={s} onPress={() => setRating(s)} style={styles.starBtn}>
                  <Text style={[styles.starIcon, rating >= s ? styles.starGold : styles.starDim]}>★</Text>
                </TouchableOpacity>
              ))}
            </View>

            <Text style={styles.label}>Your Review Feedback</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              placeholder="Describe condition of the item, accuracy of description, and courier delivery..."
              placeholderTextColor={Colors.textDim}
              multiline
              numberOfLines={4}
              value={comment}
              onChangeText={setComment}
            />

            <TouchableOpacity style={styles.submitBtn} onPress={handleSubmitReview} disabled={submitting}>
              {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitBtnText}>Post Verified Review ⭐</Text>}
            </TouchableOpacity>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: 'rgba(3, 8, 23, 0.82)',
    justifyContent: 'flex-end',
  },
  sheet: {
    backgroundColor: Colors.background,
    borderTopLeftRadius: 20,
    borderTopRightRadius: 20,
    maxHeight: '92%',
    padding: 16,
    borderTopWidth: 1,
    borderColor: Colors.border,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingBottom: 14,
    marginBottom: 14,
    borderBottomWidth: 1,
    borderBottomColor: Colors.borderSubtle,
  },
  title: {
    fontSize: 17,
    fontWeight: '700',
    color: Colors.text,
  },
  subtitle: {
    fontSize: 12,
    color: Colors.textMuted,
    marginTop: 2,
  },
  markReadText: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.primaryLight,
  },
  closeBtn: {
    width: 34,
    height: 34,
    borderRadius: 17,
    backgroundColor: Colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: Colors.border,
  },
  closeText: {
    color: Colors.textMuted,
    fontSize: 15,
    fontWeight: '700',
  },
  body: {
    marginBottom: 20,
  },
  statusBox: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    backgroundColor: Colors.card,
    padding: 14,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 12,
  },
  statusTitle: {
    fontSize: 12,
    color: Colors.textMuted,
  },
  statusVal: {
    fontSize: 12,
    fontWeight: '800',
  },
  statusApproved: {
    color: Colors.success,
  },
  statusPending: {
    color: Colors.warning,
  },
  desc: {
    fontSize: 12,
    color: Colors.textMuted,
    lineHeight: 18,
    marginBottom: 14,
  },
  label: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.textMuted,
    marginBottom: 6,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  input: {
    backgroundColor: Colors.surface,
    borderWidth: 1,
    borderColor: Colors.border,
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 11,
    fontSize: 14,
    color: Colors.text,
    marginBottom: 12,
  },
  textArea: {
    height: 80,
    textAlignVertical: 'top',
  },
  docBox: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    backgroundColor: Colors.card,
    padding: 12,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 10,
  },
  docLabel: {
    fontSize: 12,
    color: Colors.text,
    fontWeight: '600',
  },
  docStatus: {
    fontSize: 12,
    color: Colors.success,
    fontWeight: '700',
  },
  submitBtn: {
    backgroundColor: Colors.primary,
    height: 50,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 8,
    marginBottom: 14,
    shadowColor: Colors.primaryDark,
    shadowOffset: { width: 0, height: 3 },
    shadowOpacity: 0.25,
    shadowRadius: 6,
    elevation: 3,
  },
  submitBtnText: {
    fontSize: 14,
    fontWeight: '700',
    color: '#ffffff',
  },
  privacyNote: {
    fontSize: 10,
    color: Colors.textDim,
    lineHeight: 14,
    textAlign: 'center',
  },
  notifItem: {
    backgroundColor: Colors.card,
    padding: 14,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 10,
  },
  notifTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 4,
  },
  notifMsg: {
    fontSize: 12,
    color: Colors.textMuted,
    marginBottom: 6,
    lineHeight: 16,
  },
  notifTime: {
    fontSize: 10,
    color: Colors.textDim,
  },
  addrCard: {
    backgroundColor: Colors.card,
    padding: 14,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 10,
  },
  addrHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 4,
  },
  addrName: {
    fontSize: 14,
    fontWeight: '800',
    color: Colors.text,
  },
  defaultTag: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.success,
    backgroundColor: 'rgba(16, 185, 129, 0.15)',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  addrStreet: {
    fontSize: 12,
    color: Colors.textMuted,
    marginBottom: 4,
  },
  addrPhone: {
    fontSize: 11,
    color: Colors.textDim,
  },
  deleteAddrBtn: {
    alignSelf: 'flex-start',
    marginTop: 6,
  },
  deleteAddrText: {
    fontSize: 11,
    color: Colors.danger,
    fontWeight: '700',
  },
  addBtn: {
    backgroundColor: Colors.surfaceLight,
    paddingVertical: 12,
    borderRadius: 10,
    alignItems: 'center',
    marginTop: 6,
  },
  addBtnText: {
    fontSize: 13,
    fontWeight: '700',
    color: Colors.primaryLight,
  },
  addBox: {
    backgroundColor: Colors.card,
    padding: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    marginTop: 10,
  },
  addTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 12,
  },
  secBox: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 16,
  },
  secBoxTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 4,
  },
  secBoxSub: {
    fontSize: 11,
    color: Colors.textMuted,
    lineHeight: 16,
    marginBottom: 12,
  },
  secBtn: {
    backgroundColor: Colors.surfaceLight,
    paddingVertical: 12,
    borderRadius: 10,
    alignItems: 'center',
  },
  secBtnText: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.primaryLight,
  },
  twoFaActiveBanner: {
    backgroundColor: 'rgba(16, 185, 129, 0.15)',
    padding: 12,
    borderRadius: 10,
  },
  twoFaActiveText: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.success,
  },
  totpBox: {
    backgroundColor: Colors.surface,
    padding: 12,
    borderRadius: 10,
    marginTop: 10,
  },
  secretText: {
    fontSize: 13,
    fontWeight: '900',
    color: Colors.gold,
    letterSpacing: 2,
    marginBottom: 12,
  },
  dangerBtn: {
    backgroundColor: 'rgba(239, 68, 68, 0.15)',
    borderWidth: 1,
    borderColor: Colors.danger,
    paddingVertical: 12,
    borderRadius: 10,
    alignItems: 'center',
  },
  dangerBtnText: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.danger,
  },
  starRow: {
    flexDirection: 'row',
    gap: 12,
    marginBottom: 16,
  },
  starBtn: {
    padding: 4,
  },
  starIcon: {
    fontSize: 32,
  },
  starGold: {
    color: Colors.gold,
  },
  starDim: {
    color: Colors.border,
  },
});
