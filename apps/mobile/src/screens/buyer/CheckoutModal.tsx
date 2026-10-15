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
import { NotificationService } from '../../services/notificationService';

interface CheckoutModalProps {
  visible: boolean;
  orderId: number | null;
  onClose: () => void;
  onPaymentSuccess?: () => void;
}

export type PaymentRail = 'jazzcash' | 'easypaisa' | 'bank_transfer' | 'card';

export const CheckoutModal: React.FC<CheckoutModalProps> = ({
  visible,
  orderId,
  onClose,
  onPaymentSuccess,
}) => {
  const [order, setOrder] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Payment method selection
  const [paymentRail, setPaymentRail] = useState<PaymentRail>('jazzcash');
  const [intentData, setIntentData] = useState<any>(null);
  const [transactionId, setTransactionId] = useState('');
  const [receiptUrl, setReceiptUrl] = useState('https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?w=600');

  useEffect(() => {
    if (visible && orderId) {
      loadOrder();
    }
  }, [visible, orderId]);

  const loadOrder = async () => {
    setLoading(true);
    try {
      const res = await MobileApi.getOrderDetail(orderId!);
      setOrder(res.data);
    } catch (err: any) {
      Alert.alert('Order Load Error', err.message || 'Could not load order details');
    } finally {
      setLoading(false);
    }
  };

  const handleGenerateIntent = async () => {
    setSubmitting(true);
    try {
      const res = await MobileApi.createPaymentIntent(orderId!, paymentRail, 'sandbox');
      setIntentData(res.data);
      Alert.alert(
        'Escrow Payment Intent Active 🛡️',
        `Reference ID: ${res.data?.payment_intent_id || `INT-${orderId}`}\nPlease complete transfer and paste Transaction ID below.`
      );
    } catch (err: any) {
      Alert.alert('Intent Error', err.message || 'Failed to initiate payment rail');
    } finally {
      setSubmitting(false);
    }
  };

  const handleConfirmReceipt = async () => {
    if (!transactionId.trim()) {
      Alert.alert('Required', 'Please enter your mobile wallet or bank transaction ID (e.g. TID-982341).');
      return;
    }
    setSubmitting(true);
    try {
      await MobileApi.confirmPaymentReceipt(orderId!, receiptUrl, transactionId.trim());
      NotificationService.triggerPayment(
        orderId!,
        Number(order?.total_amount || order?.winning_bid || 0),
        'Held in Escrow'
      );
      Alert.alert(
        'Payment Secured in Escrow! 🛡️',
        'Your receipt has been submitted. Merchant will prepare dispatch once payment is verified.'
      );
      if (onPaymentSuccess) onPaymentSuccess();
      onClose();
    } catch (err: any) {
      Alert.alert('Submission Error', err.message || 'Could not record receipt');
    } finally {
      setSubmitting(false);
    }
  };

  if (!visible) return null;

  const winningBid = parseFloat(order?.winning_bid || order?.total_amount || 0);
  const shippingCost = parseFloat(order?.shipping_cost || 450);
  const platformFee = Math.round(winningBid * 0.025);
  const total = winningBid + shippingCost + platformFee;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.topBar}>
            <View>
              <Text style={styles.title}>Secure Escrow Checkout</Text>
              <Text style={styles.subtitle}>Order #{orderId} • Guaranteed Protection</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          {loading ? (
            <View style={styles.loaderBox}>
              <ActivityIndicator size="large" color={Colors.primary} />
            </View>
          ) : (
            <ScrollView showsVerticalScrollIndicator={false} style={styles.content}>
              {/* Order Cost Breakdown */}
              <View style={styles.card}>
                <Text style={styles.cardHeader}>Financial Summary</Text>
                <View style={styles.summaryRow}>
                  <Text style={styles.summaryLabel}>Winning Lot Bid</Text>
                  <Text style={styles.summaryVal}>PKR {winningBid.toLocaleString()}</Text>
                </View>
                <View style={styles.summaryRow}>
                  <Text style={styles.summaryLabel}>Escrow Trust Protection (2.5%)</Text>
                  <Text style={styles.summaryVal}>PKR {platformFee.toLocaleString()}</Text>
                </View>
                <View style={styles.summaryRow}>
                  <Text style={styles.summaryLabel}>Insured TCS Courier Delivery</Text>
                  <Text style={styles.summaryVal}>PKR {shippingCost.toLocaleString()}</Text>
                </View>
                <View style={styles.divider} />
                <View style={styles.summaryRow}>
                  <Text style={styles.totalLabel}>Total Payable (PKR)</Text>
                  <Text style={styles.totalVal}>PKR {total.toLocaleString()}</Text>
                </View>
              </View>

              {/* Payment Rail Selector */}
              <Text style={styles.sectionTitle}>Select Pakistani Payment Rail</Text>
              <View style={styles.railsGrid}>
                <TouchableOpacity
                  style={[styles.railCard, paymentRail === 'jazzcash' && styles.railCardActive]}
                  onPress={() => setPaymentRail('jazzcash')}
                  activeOpacity={0.8}
                >
                  <Ionicons name="phone-portrait-outline" size={22} color={Colors.warning} style={{ marginBottom: 4 }} />
                  <Text style={styles.railName}>JazzCash</Text>
                  <Text style={styles.railSub}>Mobile Wallet</Text>
                </TouchableOpacity>

                <TouchableOpacity
                  style={[styles.railCard, paymentRail === 'easypaisa' && styles.railCardActive]}
                  onPress={() => setPaymentRail('easypaisa')}
                  activeOpacity={0.8}
                >
                  <Ionicons name="wallet-outline" size={22} color={Colors.success} style={{ marginBottom: 4 }} />
                  <Text style={styles.railName}>EasyPaisa</Text>
                  <Text style={styles.railSub}>Telenor Microfinance</Text>
                </TouchableOpacity>

                <TouchableOpacity
                  style={[styles.railCard, paymentRail === 'bank_transfer' && styles.railCardActive]}
                  onPress={() => setPaymentRail('bank_transfer')}
                  activeOpacity={0.8}
                >
                  <Ionicons name="business-outline" size={22} color={Colors.primaryLight} style={{ marginBottom: 4 }} />
                  <Text style={styles.railName}>Bank Wire</Text>
                  <Text style={styles.railSub}>IBAN / Raast</Text>
                </TouchableOpacity>

                <TouchableOpacity
                  style={[styles.railCard, paymentRail === 'card' && styles.railCardActive]}
                  onPress={() => setPaymentRail('card')}
                  activeOpacity={0.8}
                >
                  <Ionicons name="card-outline" size={22} color={Colors.info} style={{ marginBottom: 4 }} />
                  <Text style={styles.railName}>Debit/Credit</Text>
                  <Text style={styles.railSub}>Visa / PayPak</Text>
                </TouchableOpacity>
              </View>

              {/* Step 1: Generate Payment Intent */}
              {!intentData ? (
                <View style={styles.intentBox}>
                  <Text style={styles.intentTitle}>Step 1: Initiate Payment Session</Text>
                  <Text style={styles.intentSub}>
                    Generating an authoritative escrow intent reserves the lot and creates a verified transaction reference.
                  </Text>
                  <TouchableOpacity
                    style={styles.intentBtn}
                    onPress={handleGenerateIntent}
                    disabled={submitting}
                  >
                    {submitting ? (
                      <ActivityIndicator color="#ffffff" />
                    ) : (
                      <Text style={styles.intentBtnText}>Initiate {paymentRail.toUpperCase()} Intent →</Text>
                    )}
                  </TouchableOpacity>
                </View>
              ) : (
                /* Step 2: Payment Receipt & TID Upload */
                <View style={styles.receiptBox}>
                  <Text style={styles.receiptTitle}>Step 2: Submit Proof of Payment</Text>
                  <View style={styles.instructionBanner}>
                    <Text style={styles.instructionText}>
                      Send PKR {total.toLocaleString()} to official NexusBids {paymentRail.toUpperCase()} Account (0300-NEXUSBIDS).
                      Reference ID: {intentData?.payment_intent_id || `NB-${orderId}`}
                    </Text>
                  </View>

                  <Text style={styles.label}>Transaction ID (TID / Reference)</Text>
                  <TextInput
                    style={styles.input}
                    placeholder="e.g. TID-89312048"
                    placeholderTextColor={Colors.textDim}
                    value={transactionId}
                    onChangeText={setTransactionId}
                  />

                  <View style={styles.receiptUploadedRow}>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                      <Ionicons name="receipt-outline" size={15} color={Colors.primaryLight} />
                      <Text style={styles.receiptUploadedLabel}>Digital Receipt Attached</Text>
                    </View>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 3 }}>
                      <Ionicons name="checkmark-circle" size={13} color={Colors.success} />
                      <Text style={styles.receiptUploadedStatus}>Ready</Text>
                    </View>
                  </View>

                  <TouchableOpacity
                    style={styles.submitBtn}
                    onPress={handleConfirmReceipt}
                    disabled={submitting}
                  >
                    {submitting ? (
                      <ActivityIndicator color="#ffffff" />
                    ) : (
                      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                        <Ionicons name="shield-checkmark" size={16} color="#ffffff" />
                        <Text style={styles.submitBtnText}>Confirm Escrow Deposit</Text>
                      </View>
                    )}
                  </TouchableOpacity>
                </View>
              )}

              {/* Escrow Guarantee Seal */}
              <View style={styles.securitySeal}>
                <View style={{ width: 34, height: 34, borderRadius: 17, backgroundColor: 'rgba(16, 185, 129, 0.15)', alignItems: 'center', justifyContent: 'center', marginRight: 12 }}>
                  <Ionicons name="shield-checkmark" size={18} color={Colors.success} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.sealTitle}>100% Buyer Escrow Protection</Text>
                  <Text style={styles.sealDesc}>
                    Funds are never paid directly to the seller until you confirm receipt of the lot in good condition.
                  </Text>
                </View>
              </View>
            </ScrollView>
          )}
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
    height: '92%',
    borderTopWidth: 1,
    borderColor: Colors.border,
  },
  topBar: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: 16,
    borderBottomWidth: 1,
    borderBottomColor: Colors.border,
  },
  title: {
    fontSize: 16,
    fontWeight: '800',
    color: Colors.text,
  },
  subtitle: {
    fontSize: 11,
    color: Colors.textMuted,
    marginTop: 2,
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
    color: Colors.textDim,
    fontSize: 15,
    fontWeight: '700',
  },
  loaderBox: {
    padding: 60,
    alignItems: 'center',
  },
  content: {
    padding: 16,
  },
  card: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 16,
  },
  cardHeader: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 12,
  },
  summaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 5,
  },
  summaryLabel: {
    fontSize: 12,
    color: Colors.textMuted,
  },
  summaryVal: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.text,
  },
  divider: {
    height: 1,
    backgroundColor: Colors.border,
    marginVertical: 8,
  },
  totalLabel: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
  },
  totalVal: {
    fontSize: 16,
    fontWeight: '900',
    color: Colors.success,
  },
  sectionTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 12,
  },
  railsGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
    marginBottom: 16,
  },
  railCard: {
    width: '48%',
    backgroundColor: Colors.card,
    borderRadius: 12,
    padding: 12,
    borderWidth: 1.5,
    borderColor: Colors.border,
  },
  railCardActive: {
    borderColor: Colors.primary,
    backgroundColor: 'rgba(99, 102, 241, 0.12)',
  },
  railIcon: {
    fontSize: 22,
    marginBottom: 4,
  },
  railName: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
  },
  railSub: {
    fontSize: 10,
    color: Colors.textMuted,
    marginTop: 2,
  },
  intentBox: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 16,
  },
  intentTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 4,
  },
  intentSub: {
    fontSize: 11,
    color: Colors.textMuted,
    lineHeight: 16,
    marginBottom: 14,
  },
  intentBtn: {
    backgroundColor: Colors.primary,
    paddingVertical: 12,
    borderRadius: 10,
    alignItems: 'center',
  },
  intentBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: '#ffffff',
  },
  receiptBox: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.primary,
    marginBottom: 16,
  },
  receiptTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 10,
  },
  instructionBanner: {
    backgroundColor: 'rgba(99, 102, 241, 0.12)',
    padding: 10,
    borderRadius: 8,
    marginBottom: 12,
  },
  instructionText: {
    fontSize: 11,
    color: Colors.primaryLight,
    lineHeight: 16,
    fontWeight: '600',
  },
  label: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.textMuted,
    marginBottom: 6,
    textTransform: 'uppercase',
  },
  input: {
    backgroundColor: Colors.surface,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: Colors.border,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 13,
    color: Colors.text,
    marginBottom: 12,
  },
  receiptUploadedRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 8,
    marginBottom: 12,
  },
  receiptUploadedLabel: {
    fontSize: 11,
    color: Colors.textMuted,
  },
  receiptUploadedStatus: {
    fontSize: 11,
    fontWeight: '800',
    color: Colors.success,
  },
  submitBtn: {
    backgroundColor: Colors.success,
    paddingVertical: 14,
    borderRadius: 10,
    alignItems: 'center',
  },
  submitBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: '#ffffff',
  },
  securitySeal: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: 'rgba(16, 185, 129, 0.08)',
    borderRadius: 12,
    padding: 14,
    borderWidth: 1,
    borderColor: 'rgba(16, 185, 129, 0.25)',
    marginBottom: 30,
  },
  sealIcon: {
    fontSize: 22,
    marginRight: 12,
  },
  sealTitle: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.success,
    marginBottom: 2,
  },
  sealDesc: {
    fontSize: 10,
    color: Colors.textMuted,
    lineHeight: 14,
  },
});
