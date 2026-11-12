import React from 'react';
import { View, Text, StyleSheet, Modal, ScrollView, TouchableOpacity, Alert } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../../theme/colors';

interface InvoiceModalProps {
  visible: boolean;
  order: any;
  onClose: () => void;
}

export const InvoiceModal: React.FC<InvoiceModalProps> = ({ visible, order, onClose }) => {
  if (!visible || !order) return null;

  const orderId = order.order_id || 101;
  const invoiceNumber = `INV-PK-${new Date().getFullYear()}-${String(orderId).padStart(6, '0')}`;
  const bidAmount = parseFloat(order.winning_bid || order.total_amount || 0);
  const shippingFee = parseFloat(order.shipping_cost || 450);
  const platformFee = Math.round(bidAmount * 0.025);
  const totalAmount = bidAmount + shippingFee + platformFee;

  const handleDownloadInvoice = () => {
    Alert.alert(
      'Invoice Exported',
      `Invoice #${invoiceNumber} has been saved to your device documents.`
    );
  };

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <View style={styles.topBar}>
            <View>
              <Text style={styles.title}>Tax Invoice & Escrow Receipt</Text>
              <Text style={styles.invoiceNo}>{invoiceNumber}</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.content}>
            {/* Pakistan Government & Platform Header */}
            <View style={styles.headerBox}>
              <View>
                <Text style={styles.brandTitle}>NexusBids Pakistan</Text>
                <Text style={styles.brandSubtitle}>Online Auction Marketplace & Escrow Gateway</Text>
                <Text style={styles.taxId}>NTN: 8943210-4 • FBR Sales Tax Registered</Text>
              </View>
              <View style={styles.escrowStamp}>
                <Ionicons name="shield-checkmark" size={12} color={Colors.success} style={{ marginRight: 4 }} />
                <Text style={styles.escrowStampText}>ESCROW VERIFIED</Text>
              </View>
            </View>

            {/* Bill Details */}
            <View style={styles.infoGrid}>
              <View style={styles.infoCol}>
                <Text style={styles.infoLabel}>BILLED TO (BUYER):</Text>
                <Text style={styles.infoVal}>{order.buyer_name || 'Verified Buyer'}</Text>
                <Text style={styles.infoSub}>Address: {order.shipping_address || 'Pakistan'}</Text>
                <Text style={styles.infoSub}>NADRA Status: Verified</Text>
              </View>
              <View style={styles.infoCol}>
                <Text style={styles.infoLabel}>DISPATCHED FROM (SELLER):</Text>
                <Text style={styles.infoVal}>{order.seller_name || 'Verified Merchant'}</Text>
                <Text style={styles.infoSub}>Fulfillment: Insured Courier</Text>
                <Text style={styles.infoSub}>Date: {new Date(order.created_at || Date.now()).toLocaleDateString()}</Text>
              </View>
            </View>

            {/* Itemized Table */}
            <View style={styles.table}>
              <View style={styles.tableHeader}>
                <Text style={[styles.th, { flex: 2 }]}>Description</Text>
                <Text style={[styles.th, { flex: 1, textAlign: 'right' }]}>Amount</Text>
              </View>

              <View style={styles.tableRow}>
                <View style={{ flex: 2 }}>
                  <Text style={styles.itemTitle}>{order.auction_title || `Auction Lot #${order.auction_id || orderId}`}</Text>
                  <Text style={styles.itemSub}>Winning Auction Lot Bid</Text>
                </View>
                <Text style={[styles.td, { flex: 1, textAlign: 'right' }]}>
                  PKR {bidAmount.toLocaleString()}
                </Text>
              </View>

              <View style={styles.tableRow}>
                <View style={{ flex: 2 }}>
                  <Text style={styles.itemTitle}>Escrow Protection Guarantee (2.5%)</Text>
                  <Text style={styles.itemSub}>Full refund warranty & dispute protection</Text>
                </View>
                <Text style={[styles.td, { flex: 1, textAlign: 'right' }]}>
                  PKR {platformFee.toLocaleString()}
                </Text>
              </View>

              <View style={styles.tableRow}>
                <View style={{ flex: 2 }}>
                  <Text style={styles.itemTitle}>Insured Courier Delivery</Text>
                  <Text style={styles.itemSub}>Tracked TCS / Leopards Courier Express</Text>
                </View>
                <Text style={[styles.td, { flex: 1, textAlign: 'right' }]}>
                  PKR {shippingFee.toLocaleString()}
                </Text>
              </View>

              {/* Total Row */}
              <View style={styles.totalRow}>
                <Text style={styles.totalLabel}>Grand Total (PKR):</Text>
                <Text style={styles.totalAmount}>PKR {totalAmount.toLocaleString()}</Text>
              </View>
            </View>

            {/* Payment & Escrow Status Notice */}
            <View style={styles.noticeBox}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: 4 }}>
                <Ionicons name="shield-checkmark" size={16} color={Colors.primaryLight} />
                <Text style={styles.noticeTitle}>NexusBids Escrow Safe-Vault</Text>
              </View>
              <Text style={styles.noticeText}>
                Your funds are safely held in trust under State Bank of Pakistan compliant escrow protocols until you receive and inspect your items.
              </Text>
            </View>

            <TouchableOpacity style={styles.downloadBtn} onPress={handleDownloadInvoice}>
              <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8 }}>
                <Ionicons name="document-text-outline" size={16} color="#ffffff" />
                <Text style={styles.downloadBtnText}>Download Official PDF</Text>
              </View>
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
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    height: '88%',
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
  title: {
    fontSize: 16,
    fontWeight: '800',
    color: Colors.text,
  },
  invoiceNo: {
    fontSize: 11,
    color: Colors.primary,
    fontWeight: '700',
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
  content: {
    padding: 16,
  },
  headerBox: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 14,
    shadowColor: Colors.text,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 4,
    elevation: 2,
  },
  brandTitle: {
    fontSize: 15,
    fontWeight: '900',
    color: Colors.text,
  },
  brandSubtitle: {
    fontSize: 10,
    color: Colors.textMuted,
    marginTop: 2,
  },
  taxId: {
    fontSize: 10,
    color: Colors.textDim,
    marginTop: 4,
  },
  escrowStamp: {
    backgroundColor: Colors.successLight,
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: Colors.success,
  },
  escrowStampText: {
    fontSize: 9,
    fontWeight: '800',
    color: Colors.success,
  },
  infoGrid: {
    flexDirection: 'row',
    gap: 10,
    marginBottom: 14,
  },
  infoCol: {
    flex: 1,
    backgroundColor: Colors.card,
    padding: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: Colors.border,
    shadowColor: Colors.text,
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.04,
    shadowRadius: 2,
    elevation: 1,
  },
  infoLabel: {
    fontSize: 9,
    fontWeight: '800',
    color: Colors.textDim,
    marginBottom: 4,
  },
  infoVal: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.text,
  },
  infoSub: {
    fontSize: 10,
    color: Colors.textMuted,
    marginTop: 2,
  },
  table: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    padding: 14,
    marginBottom: 14,
    shadowColor: Colors.text,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 4,
    elevation: 2,
  },
  tableHeader: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: Colors.borderSubtle,
    paddingBottom: 8,
    marginBottom: 8,
  },
  th: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.textDim,
    textTransform: 'uppercase',
  },
  tableRow: {
    flexDirection: 'row',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: Colors.borderSubtle,
  },
  itemTitle: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.text,
  },
  itemSub: {
    fontSize: 10,
    color: Colors.textMuted,
  },
  td: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.text,
  },
  totalRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingTop: 12,
    marginTop: 4,
  },
  totalLabel: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
  },
  totalAmount: {
    fontSize: 16,
    fontWeight: '900',
    color: Colors.primary,
  },
  noticeBox: {
    backgroundColor: Colors.surface,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: Colors.border,
    padding: 12,
    marginBottom: 20,
  },
  noticeTitle: {
    fontSize: 11,
    fontWeight: '800',
    color: Colors.primary,
    marginBottom: 2,
  },
  noticeText: {
    fontSize: 10,
    color: Colors.textMuted,
    lineHeight: 14,
  },
  downloadBtn: {
    backgroundColor: Colors.primary,
    paddingVertical: 14,
    borderRadius: 12,
    alignItems: 'center',
    marginBottom: 30,
    shadowColor: Colors.primaryDark,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.25,
    shadowRadius: 4,
    elevation: 3,
  },
  downloadBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: '#ffffff',
  },
});
