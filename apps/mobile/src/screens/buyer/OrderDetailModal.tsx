import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  Modal,
  ScrollView,
  TouchableOpacity,
  ActivityIndicator,
  Alert,
  TextInput,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../../theme/colors';
import { MobileApi } from '../../api/client';
import { NotificationService } from '../../services/notificationService';
import { InvoiceModal } from './InvoiceModal';

interface OrderDetailModalProps {
  visible: boolean;
  orderId: number | null;
  onClose: () => void;
  onRefreshOrders?: () => void;
  onOpenReview?: (sellerId: number, orderId: number) => void;
  onOpenCheckout?: (orderId: number) => void;
}

export const OrderDetailModal: React.FC<OrderDetailModalProps> = ({
  visible,
  orderId,
  onClose,
  onRefreshOrders,
  onOpenReview,
  onOpenCheckout,
}) => {
  const [order, setOrder] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [actionLoading, setActionLoading] = useState(false);
  const [invoiceVisible, setInvoiceVisible] = useState(false);

  // Dispute modal toggle
  const [showDispute, setShowDispute] = useState(false);
  const [disputeReason, setDisputeReason] = useState('Item damaged during transit');
  const [disputeDesc, setDisputeDesc] = useState('');

  const loadOrderDetail = async () => {
    if (!orderId) return;
    setLoading(true);
    try {
      const res = await MobileApi.getOrderDetail(orderId);
      setOrder(res.data);
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Could not load order');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (visible && orderId) {
      loadOrderDetail();
    }
  }, [visible, orderId]);

  const handleConfirmDelivery = () => {
    Alert.alert(
      'Confirm Delivery & Release Escrow 🛡️',
      'Have you received the item in satisfactory condition? Confirming will immediately release escrow funds to the seller.',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Confirm & Release',
          style: 'default',
          onPress: async () => {
            setActionLoading(true);
            try {
              await MobileApi.confirmDelivery(orderId!, true);
              NotificationService.triggerPayment(orderId!, order?.total_amount || 0, 'Released to Seller');
              Alert.alert('Delivery Confirmed! 🎉', 'Escrow funds have been released to the seller. Thank you!');
              loadOrderDetail();
              if (onRefreshOrders) onRefreshOrders();
            } catch (err: any) {
              Alert.alert('Error', err.message || 'Failed to confirm delivery');
            } finally {
              setActionLoading(false);
            }
          },
        },
      ]
    );
  };

  const handleRaiseDispute = async () => {
    if (!disputeDesc.trim()) {
      Alert.alert('Required', 'Please describe the issue with your item.');
      return;
    }
    setActionLoading(true);
    try {
      await MobileApi.raiseDispute({
        order_id: orderId!,
        reason: disputeReason,
        description: disputeDesc,
      });
      Alert.alert('Dispute Logged ⚖️', 'NexusBids Trust & Safety team will review your case. Escrow funds are frozen.');
      setShowDispute(false);
      loadOrderDetail();
      if (onRefreshOrders) onRefreshOrders();
    } catch (err: any) {
      Alert.alert('Dispute Error', err.message || 'Could not lodge dispute');
    } finally {
      setActionLoading(false);
    }
  };

  if (!visible) return null;

  const currentStep = () => {
    if (!order) return 1;
    if (order.order_status === 'delivered' || order.order_status === 'completed') return 5;
    if (order.shipping_status === 'in_transit' || order.shipping_status === 'shipped') return 4;
    if (order.payment_status === 'paid') return 3;
    if (order.payment_status === 'receipt_uploaded' || order.payment_status === 'initiated') return 2;
    return 1;
  };

  const step = currentStep();

  return (
    <>
      <Modal visible={visible} animationType="slide" transparent>
        <View style={styles.overlay}>
          <View style={styles.sheet}>
            <View style={styles.topBar}>
              <View>
                <Text style={styles.orderTag}>ORDER #{orderId}</Text>
                <Text style={styles.orderDate}>
                  Placed on {new Date(order?.created_at || Date.now()).toLocaleDateString()}
                </Text>
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
              <ScrollView showsVerticalScrollIndicator={false} style={styles.body}>
                {/* Order Item Header */}
                <View style={styles.itemHeaderCard}>
                  <Text style={styles.itemTitle}>{order?.auction_title || 'Auction Lot'}</Text>
                  <Text style={styles.itemPrice}>
                    PKR {Number(order?.total_amount || order?.winning_bid || 0).toLocaleString()}
                  </Text>
                </View>

                {/* 5-Step Escrow Timeline Tracker */}
                <View style={styles.timelineCard}>
                  <Text style={styles.timelineTitle}>Fulfillment Progress</Text>

                  <View style={styles.stepRow}>
                    <View style={[styles.stepDot, step >= 1 && styles.stepDotActive]}>
                      {step >= 1
                        ? <Ionicons name="checkmark" size={14} color="#ffffff" />
                        : <Text style={styles.stepDotNum}>1</Text>}
                    </View>
                    <View style={styles.stepInfo}>
                      <Text style={[styles.stepLabel, step >= 1 && styles.stepLabelActive]}>Order Placed</Text>
                      <Text style={styles.stepSub}>Auction ended and invoice created</Text>
                    </View>
                  </View>

                  <View style={styles.stepRow}>
                    <View style={[styles.stepDot, step >= 2 && styles.stepDotActive]}>
                      {step >= 2
                        ? <Ionicons name="checkmark" size={14} color="#ffffff" />
                        : <Text style={styles.stepDotNum}>2</Text>}
                    </View>
                    <View style={styles.stepInfo}>
                      <Text style={[styles.stepLabel, step >= 2 && styles.stepLabelActive]}>Escrow Payment Secured</Text>
                      <Text style={styles.stepSub}>Funds held safely in trust</Text>
                    </View>
                  </View>

                  <View style={styles.stepRow}>
                    <View style={[styles.stepDot, step >= 3 && styles.stepDotActive]}>
                      {step >= 3
                        ? <Ionicons name="checkmark" size={14} color="#ffffff" />
                        : <Text style={styles.stepDotNum}>3</Text>}
                    </View>
                    <View style={styles.stepInfo}>
                      <Text style={[styles.stepLabel, step >= 3 && styles.stepLabelActive]}>Packed by Seller</Text>
                      <Text style={styles.stepSub}>Item inspected and packaged</Text>
                    </View>
                  </View>

                  <View style={styles.stepRow}>
                    <View style={[styles.stepDot, step >= 4 && styles.stepDotActive]}>
                      {step >= 4
                        ? <Ionicons name="checkmark" size={14} color="#ffffff" />
                        : <Text style={styles.stepDotNum}>4</Text>}
                    </View>
                    <View style={styles.stepInfo}>
                      <Text style={[styles.stepLabel, step >= 4 && styles.stepLabelActive]}>In Transit via Courier</Text>
                      <Text style={styles.stepSub}>
                        {order?.courier || 'TCS Express'} • Tracking: {order?.tracking_number || 'Pending'}
                      </Text>
                    </View>
                  </View>

                  <View style={styles.stepRow}>
                    <View style={[styles.stepDot, step >= 5 && styles.stepDotActive]}>
                      {step >= 5
                        ? <Ionicons name="checkmark" size={14} color="#ffffff" />
                        : <Text style={styles.stepDotNum}>5</Text>}
                    </View>
                    <View style={styles.stepInfo}>
                      <Text style={[styles.stepLabel, step >= 5 && styles.stepLabelActive]}>Delivered & Settled</Text>
                      <Text style={styles.stepSub}>Buyer confirmed receipt, escrow released</Text>
                    </View>
                  </View>
                </View>

                {/* Unpaid Order - Complete Escrow Payment Action */}
                {(order?.payment_status === 'unpaid' || (order?.payment_status === 'pending' && !order?.buyer_proof_uploaded_at)) && onOpenCheckout ? (
                  <TouchableOpacity
                    style={[styles.confirmDeliveryBtn, { backgroundColor: Colors.success, marginBottom: 12 }]}
                    onPress={() => {
                      onClose();
                      onOpenCheckout(orderId!);
                    }}
                  >
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                      <Ionicons name="card-outline" size={16} color="#ffffff" />
                      <Text style={styles.confirmDeliveryBtnText}>Pay Now / Escrow Checkout</Text>
                    </View>
                  </TouchableOpacity>
                ) : null}

                {/* Delivery Confirmation Action */}
                {order?.shipping_status === 'shipped' || step === 4 ? (
                  <TouchableOpacity
                    style={styles.confirmDeliveryBtn}
                    onPress={handleConfirmDelivery}
                    disabled={actionLoading}
                  >
                    {actionLoading ? (
                      <ActivityIndicator color="#ffffff" />
                    ) : (
                      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                        <Ionicons name="checkmark-circle" size={16} color="#ffffff" />
                        <Text style={styles.confirmDeliveryBtnText}>Confirm Delivery & Release Escrow</Text>
                      </View>
                    )}
                  </TouchableOpacity>
                ) : null}

                {/* If completed, prompt for review */}
                {(order?.order_status === 'delivered' || order?.order_status === 'completed') && onOpenReview && (
                  <TouchableOpacity
                    style={styles.reviewBtn}
                    onPress={() => onOpenReview(order?.seller_id || 1, orderId!)}
                  >
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                      <Ionicons name="star" size={15} color="#ffffff" />
                      <Text style={styles.reviewBtnText}>Review Merchant Performance</Text>
                    </View>
                  </TouchableOpacity>
                )}

                {/* Action Buttons: Invoice & Dispute */}
                <View style={styles.actionsRow}>
                  <TouchableOpacity
                    style={styles.actionBtn}
                    onPress={() => setInvoiceVisible(true)}
                  >
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                      <Ionicons name="document-text-outline" size={15} color={Colors.text} />
                      <Text style={styles.actionBtnText}>View Tax Invoice</Text>
                    </View>
                  </TouchableOpacity>

                  <TouchableOpacity
                    style={[styles.actionBtn, styles.disputeBtn]}
                    onPress={() => setShowDispute(true)}
                  >
                    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                      <Ionicons name="alert-circle-outline" size={15} color={Colors.danger} />
                      <Text style={[styles.actionBtnText, { color: Colors.danger }]}>Raise Dispute</Text>
                    </View>
                  </TouchableOpacity>
                </View>

                {/* Dispute Form Modal inside screen */}
                {showDispute && (
                  <View style={styles.disputeBox}>
                    <Text style={styles.disputeTitle}>Lodge Escrow Dispute</Text>
                    <Text style={styles.disputeSub}>
                      Raising a dispute pauses all fund transfers. Our mediation team will investigate within 24 hours.
                    </Text>

                    <Text style={styles.label}>Reason for Dispute</Text>
                    <TextInput
                      style={styles.input}
                      value={disputeReason}
                      onChangeText={setDisputeReason}
                      placeholder="e.g. Counterfeit item, damaged box"
                      placeholderTextColor={Colors.textDim}
                    />

                    <Text style={styles.label}>Detailed Description</Text>
                    <TextInput
                      style={[styles.input, styles.textArea]}
                      value={disputeDesc}
                      onChangeText={setDisputeDesc}
                      placeholder="Provide all facts regarding the condition received..."
                      placeholderTextColor={Colors.textDim}
                      multiline
                      numberOfLines={3}
                    />

                    <TouchableOpacity
                      style={styles.submitDisputeBtn}
                      onPress={handleRaiseDispute}
                      disabled={actionLoading}
                    >
                      {actionLoading ? (
                        <ActivityIndicator color="#ffffff" />
                      ) : (
                        <Text style={styles.submitDisputeBtnText}>Submit Escrow Dispute</Text>
                      )}
                    </TouchableOpacity>
                  </View>
                )}
              </ScrollView>
            )}
          </View>
        </View>
      </Modal>

      <InvoiceModal
        visible={invoiceVisible}
        order={order}
        onClose={() => setInvoiceVisible(false)}
      />
    </>
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
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    height: '92%',
    borderTopWidth: 1,
    borderColor: Colors.border,
    shadowColor: Colors.text,
    shadowOffset: { width: 0, height: -4 },
    shadowOpacity: 0.12,
    shadowRadius: 10,
    elevation: 10,
  },
  topBar: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: 16,
    borderBottomWidth: 1,
    borderBottomColor: Colors.border,
    backgroundColor: Colors.card,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
  },
  orderTag: {
    fontSize: 15,
    fontWeight: '800',
    color: Colors.primary,
  },
  orderDate: {
    fontSize: 11,
    color: Colors.textMuted,
    marginTop: 2,
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
    color: Colors.text,
    fontSize: 15,
    fontWeight: '700',
  },
  loaderBox: {
    padding: 60,
    alignItems: 'center',
  },
  body: {
    padding: 16,
  },
  itemHeaderCard: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 16,
    shadowColor: Colors.text,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 4,
    elevation: 2,
  },
  itemTitle: {
    fontSize: 16,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 6,
  },
  itemPrice: {
    fontSize: 18,
    fontWeight: '900',
    color: Colors.primary,
  },
  timelineCard: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 16,
    shadowColor: Colors.text,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 4,
    elevation: 2,
  },
  timelineTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 14,
  },
  stepRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 16,
  },
  stepDot: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: Colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 12,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  stepDotNum: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.textDim,
  },
  stepDotActive: {
    backgroundColor: Colors.primary,
    borderColor: Colors.primary,
  },
  stepInfo: {
    flex: 1,
  },
  stepLabel: {
    fontSize: 13,
    fontWeight: '700',
    color: Colors.textDim,
  },
  stepLabelActive: {
    color: Colors.text,
  },
  stepSub: {
    fontSize: 11,
    color: Colors.textMuted,
    marginTop: 2,
  },
  confirmDeliveryBtn: {
    backgroundColor: Colors.primary,
    paddingVertical: 14,
    borderRadius: 12,
    alignItems: 'center',
    marginBottom: 12,
    shadowColor: Colors.primaryDark,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.25,
    shadowRadius: 4,
    elevation: 3,
  },
  confirmDeliveryBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: '#ffffff',
  },
  reviewBtn: {
    backgroundColor: Colors.warningLight,
    borderWidth: 1,
    borderColor: Colors.warning,
    paddingVertical: 12,
    borderRadius: 12,
    alignItems: 'center',
    marginBottom: 12,
  },
  reviewBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.warning,
  },
  actionsRow: {
    flexDirection: 'row',
    gap: 10,
    marginBottom: 20,
  },
  actionBtn: {
    flex: 1,
    backgroundColor: Colors.card,
    borderWidth: 1,
    borderColor: Colors.border,
    paddingVertical: 12,
    borderRadius: 10,
    alignItems: 'center',
  },
  actionBtnText: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.text,
  },
  disputeBtn: {
    backgroundColor: Colors.dangerLight,
    borderColor: Colors.danger,
  },
  disputeBox: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.danger,
    marginBottom: 30,
    shadowColor: Colors.text,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 4,
    elevation: 2,
  },
  disputeTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: Colors.danger,
    marginBottom: 4,
  },
  disputeSub: {
    fontSize: 11,
    color: Colors.textMuted,
    lineHeight: 16,
    marginBottom: 12,
  },
  label: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.textDim,
    marginBottom: 4,
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
  textArea: {
    height: 70,
    textAlignVertical: 'top',
  },
  submitDisputeBtn: {
    backgroundColor: Colors.danger,
    paddingVertical: 14,
    borderRadius: 10,
    alignItems: 'center',
    shadowColor: Colors.danger,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.25,
    shadowRadius: 4,
    elevation: 3,
  },
  submitDisputeBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: '#ffffff',
  },
});
