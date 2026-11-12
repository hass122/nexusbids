import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  TouchableOpacity,
  TextInput,
  Modal,
  Alert,
  ActivityIndicator,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../../theme/colors';
import { MobileApi } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { LoadingSkeleton } from '../../components/LoadingSkeleton';
import { EmptyState } from '../../components/EmptyState';
import { NotificationService } from '../../services/notificationService';

// ─── 1. Seller Dashboard View ─────────────────────────────────
export const SellerDashboardScreen: React.FC<{
  onOpenCreate: () => void;
  onGoToPayouts: () => void;
  onGoToOrders: () => void;
  onOpenAnalytics: () => void;
  onOpenSellerKyc: () => void;
}> = ({ onOpenCreate, onGoToPayouts, onGoToOrders, onOpenAnalytics, onOpenSellerKyc }) => {
  const { user } = useAuth();
  const [stats, setStats] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  const loadDashboard = async () => {
    try {
      const [dashRes, payoutRes, analyticsRes] = await Promise.all([
        MobileApi.getSellerDashboard().catch(() => ({ data: {} })),
        MobileApi.getSellerPayouts().catch(() => ({ data: {} })),
        MobileApi.getSellerAnalytics(7).catch(() => ({ data: {} })),
      ]);
      setStats({
        ...dashRes.data,
        ...payoutRes.data,
        ...analyticsRes.data,
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadDashboard();
  }, []);

  if (loading) return <LoadingSkeleton count={2} message="Loading Seller Studio..." />;

  const available = stats?.available_balance || 0;
  const pending = stats?.pending_balance || stats?.escrow_held || 0;
  const activeCount = stats?.active_auctions_count || stats?.active_count || stats?.active_auctions || 0;
  const pendingOrders = stats?.pending_orders_count || 0;
  const conversionRate = stats?.conversion_rate || 0;

  return (
    <ScrollView style={styles.container} showsVerticalScrollIndicator={false}>
      {/* Wallet Balance Hero Card */}
      <View style={styles.walletCard}>
        <View style={styles.walletHeaderRow}>
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            <Ionicons name="shield-checkmark" size={13} color={Colors.gold} style={{ marginRight: 5 }} />
            <Text style={styles.walletTag}>SELLER ESCROW WALLET</Text>
          </View>
          {user?.seller_kyc_status === 'approved' ? (
            <View style={[styles.kycActiveBadge, { flexDirection: 'row', alignItems: 'center' }]}>
              <Ionicons name="checkmark" size={11} color={Colors.success} style={{ marginRight: 3 }} />
              <Text style={{ fontSize: 10, fontWeight: '800', color: Colors.success }}>KYC Approved</Text>
            </View>
          ) : (
            <TouchableOpacity onPress={onOpenSellerKyc} style={[styles.kycWarnBadge, { flexDirection: 'row', alignItems: 'center' }]} activeOpacity={0.8}>
              <Ionicons name="alert-circle" size={11} color={Colors.warning} style={{ marginRight: 3 }} />
              <Text style={styles.kycWarnText}>Complete KYC</Text>
            </TouchableOpacity>
          )}
        </View>

        <Text style={styles.walletAmount}>PKR {Number(available).toLocaleString()}</Text>
        <Text style={styles.walletSub}>Available for immediate disbursement</Text>

        <View style={styles.walletRow}>
          <View style={styles.miniBal}>
            <Text style={styles.miniBalLbl}>Held in Escrow</Text>
            <Text style={styles.miniBalVal}>PKR {Number(pending).toLocaleString()}</Text>
          </View>
          <TouchableOpacity style={styles.payoutBtn} onPress={onGoToPayouts} activeOpacity={0.85}>
            <Text style={styles.payoutBtnText}>Withdraw Funds</Text>
            <Ionicons name="arrow-forward" size={13} color="#ffffff" style={{ marginLeft: 4 }} />
          </TouchableOpacity>
        </View>
      </View>

      {/* Quick Stats Grid */}
      <View style={styles.statsGrid}>
        <View style={styles.statBox}>
          <Ionicons name="pricetags-outline" size={20} color={Colors.primaryLight} style={{ marginBottom: 6 }} />
          <Text style={styles.statVal}>{activeCount}</Text>
          <Text style={styles.statLbl}>Active Listings</Text>
        </View>
        <TouchableOpacity style={styles.statBox} onPress={onGoToOrders} activeOpacity={0.75}>
          <Ionicons name="cube-outline" size={20} color={Colors.warning} style={{ marginBottom: 6 }} />
          <Text style={styles.statVal}>{pendingOrders}</Text>
          <Text style={styles.statLbl}>Orders to Ship</Text>
        </TouchableOpacity>
        <TouchableOpacity style={styles.statBox} onPress={onOpenAnalytics} activeOpacity={0.75}>
          <Ionicons name="trending-up-outline" size={20} color={Colors.success} style={{ marginBottom: 6 }} />
          <Text style={styles.statVal}>{conversionRate}%</Text>
          <Text style={styles.statLbl}>Sell-Through</Text>
        </TouchableOpacity>
      </View>

      {/* Action CTA: Create Auction */}
      <TouchableOpacity style={styles.createBtn} onPress={onOpenCreate} activeOpacity={0.85}>
        <View style={styles.createBtnIconCircle}>
          <Ionicons name="add" size={20} color="#ffffff" />
        </View>
        <View style={styles.createBtnTextWrap}>
          <Text style={styles.createBtnTitle}>Create New Auction Lot</Text>
          <Text style={styles.createBtnSub}>List an item with starting bid and reserve price</Text>
        </View>
        <Ionicons name="chevron-forward" size={18} color={Colors.primaryLight} />
      </TouchableOpacity>

      {/* Analytics Shortcut */}
      <TouchableOpacity style={styles.analyticsShortcut} onPress={onOpenAnalytics} activeOpacity={0.85}>
        <View style={styles.analyticsIconCircle}>
          <Ionicons name="stats-chart-outline" size={18} color={Colors.primaryLight} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.analyticsTitle}>Auction Performance & Analytics</Text>
          <Text style={styles.analyticsSub}>View total lot impressions, highest bids, and daily revenue</Text>
        </View>
        <Ionicons name="chevron-forward" size={18} color={Colors.textDim} />
      </TouchableOpacity>
    </ScrollView>
  );
};

// ─── 2. Seller Listings View ──────────────────────────────────
export const SellerListingsScreen: React.FC<{
  onOpenCreate: () => void;
  onEditAuction: (auction: any) => void;
}> = ({ onOpenCreate, onEditAuction }) => {
  const [listings, setListings] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'all' | 'active' | 'sold'>('all');

  const loadListings = async () => {
    try {
      const res = await MobileApi.getMySellerAuctions();
      setListings(res.data || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadListings();
  }, []);

  const filtered = listings.filter((item) => {
    if (filter === 'active') return item.auction_status === 'active';
    if (filter === 'sold') return item.auction_status === 'sold' || item.auction_status === 'ended';
    return true;
  });

  return (
    <View style={styles.container}>
      <View style={styles.sectionHeader}>
        <Text style={styles.screenTitle}>My Inventory ({listings.length})</Text>
        <TouchableOpacity style={styles.addMiniBtn} onPress={onOpenCreate}>
          <Text style={styles.addMiniText}>+ New Lot</Text>
        </TouchableOpacity>
      </View>

      {/* Status Filter Tabs */}
      <View style={styles.filterBar}>
        <TouchableOpacity
          style={[styles.filterChip, filter === 'all' && styles.filterChipActive]}
          onPress={() => setFilter('all')}
        >
          <Text style={[styles.filterChipText, filter === 'all' && styles.filterChipTextActive]}>
            All ({listings.length})
          </Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.filterChip, filter === 'active' && styles.filterChipActive]}
          onPress={() => setFilter('active')}
        >
          <Text style={[styles.filterChipText, filter === 'active' && styles.filterChipTextActive]}>
            Active
          </Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.filterChip, filter === 'sold' && styles.filterChipActive]}
          onPress={() => setFilter('sold')}
        >
          <Text style={[styles.filterChipText, filter === 'sold' && styles.filterChipTextActive]}>
            Sold / Ended
          </Text>
        </TouchableOpacity>
      </View>

      {loading ? (
        <LoadingSkeleton count={2} />
      ) : filtered.length === 0 ? (
        <EmptyState
          iconName="pricetags-outline"
          title="No Auctions Created"
          subtitle="You haven't listed any items in this category yet."
          buttonText="Create First Auction"
          onButtonPress={onOpenCreate}
        />
      ) : (
        <ScrollView showsVerticalScrollIndicator={false}>
          {filtered.map((item) => (
            <View key={item.auction_id} style={styles.listingCard}>
              <View style={styles.listingHeader}>
                <Text style={styles.listingTitle} numberOfLines={1}>
                  {item.title}
                </Text>
                <Text
                  style={[
                    styles.statusTag,
                    item.auction_status === 'active' ? styles.statusActive : styles.statusEnded,
                  ]}
                >
                  {(item.auction_status || 'ACTIVE').toUpperCase()}
                </Text>
              </View>

              <Text style={styles.listingPrice}>
                Current Bid: PKR {Number(item.current_bid || item.starting_price).toLocaleString()}
              </Text>
              <Text style={styles.listingMeta}>
                Views: {item.view_count || 0} • Bids: {item.bid_count || 0} • Ends:{' '}
                {new Date(item.end_time || Date.now()).toLocaleDateString()}
              </Text>

              <View style={styles.listingActionsRow}>
                <TouchableOpacity
                  style={[styles.editListingBtn, { flexDirection: 'row', alignItems: 'center' }]}
                  onPress={() => onEditAuction(item)}
                  activeOpacity={0.8}
                >
                  <Ionicons name="create-outline" size={13} color={Colors.text} style={{ marginRight: 4 }} />
                  <Text style={styles.editListingText}>Edit Listing</Text>
                </TouchableOpacity>

                {['unsold', 'cancelled', 'ended'].includes(item.auction_status) && (
                  <TouchableOpacity
                    style={[
                      styles.editListingBtn,
                      { backgroundColor: Colors.primaryDark, marginLeft: 8, flexDirection: 'row', alignItems: 'center' }
                    ]}
                    onPress={() => {
                      Alert.alert(
                        'Relist Auction',
                        'Do you want to relist this lot? Photos and descriptions will be carried over with fresh bids and stats.',
                        [
                          { text: 'Cancel', style: 'cancel' },
                          {
                            text: 'Relist Now',
                            onPress: async () => {
                              try {
                                await MobileApi.relistAuction(item.auction_id);
                                Alert.alert('Success', 'Auction relisted successfully!');
                                loadListings();
                              } catch (err: any) {
                                Alert.alert('Error', err.message || 'Failed to relist');
                              }
                            },
                          },
                        ]
                      );
                    }}
                    activeOpacity={0.8}
                  >
                    <Ionicons name="refresh-outline" size={13} color="#ffffff" style={{ marginRight: 4 }} />
                    <Text style={[styles.editListingText, { color: '#ffffff' }]}>Relist Lot</Text>
                  </TouchableOpacity>
                )}
              </View>
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
};

// ─── 3. Create Auction Modal ──────────────────────────────────
export const CreateAuctionModal: React.FC<{
  visible: boolean;
  onClose: () => void;
  onSuccess: () => void;
}> = ({ visible, onClose, onSuccess }) => {
  const [title, setTitle] = useState('');
  const [desc, setDesc] = useState('');
  const [categoryId, setCategoryId] = useState('1');
  const [startingPrice, setStartingPrice] = useState('');
  const [reservePrice, setReservePrice] = useState('');
  const [minIncrement, setMinIncrement] = useState('500');
  const [durationDays, setDurationDays] = useState('3');
  const [submitting, setSubmitting] = useState(false);

  const handleCreate = async () => {
    if (!title.trim() || !startingPrice.trim()) {
      Alert.alert('Required', 'Item title and starting bid price are required.');
      return;
    }

    setSubmitting(true);
    try {
      const startPriceNum = parseFloat(startingPrice);
      const resPriceNum = reservePrice ? parseFloat(reservePrice) : null;
      const minIncNum = parseFloat(minIncrement) || 500;
      const days = parseInt(durationDays, 10) || 3;
      const endDate = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toISOString();

      await MobileApi.createAuction({
        title: title.trim(),
        description: desc.trim(),
        category_id: parseInt(categoryId, 10) || 1,
        starting_price: startPriceNum,
        reserve_price: resPriceNum,
        bid_increment: minIncNum,
        min_increment: minIncNum,
        end_time: endDate,
        image: 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600',
      });

      NotificationService.notify({
        type: 'messages',
        title: '🏷️ Auction Published!',
        message: `Lot "${title}" is now active and accepting bids.`,
      });

      Alert.alert('Success! 🎉', 'Your auction listing has been submitted and published live.');
      onSuccess();
      onClose();
    } catch (err: any) {
      Alert.alert('Listing Error', err.message || 'Failed to create auction lot');
    } finally {
      setSubmitting(false);
    }
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.modalOverlay}>
        <View style={styles.modalSheet}>
          <View style={styles.modalHeader}>
            <View>
              <Text style={styles.modalTitle}>List New Auction Item</Text>
              <Text style={styles.modalSubtitle}>Pakistani Escrow Protection Applies</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.modalBody}>
            <Text style={styles.label}>Item Title</Text>
            <TextInput
              style={styles.input}
              placeholder="e.g. Rolex Submariner Date 41mm"
              placeholderTextColor={Colors.textDim}
              value={title}
              onChangeText={setTitle}
            />

            <Text style={styles.label}>Category</Text>
            <View style={styles.catChipsRow}>
              {[
                { id: '1', label: 'Watches', icon: 'watch-outline' as const },
                { id: '2', label: 'Tech', icon: 'laptop-outline' as const },
                { id: '3', label: 'Autos', icon: 'car-sport-outline' as const },
                { id: '4', label: 'Rare', icon: 'diamond-outline' as const },
              ].map((c) => (
                <TouchableOpacity
                  key={c.id}
                  style={[styles.catChip, categoryId === c.id && styles.catChipActive]}
                  onPress={() => setCategoryId(c.id)}
                >
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
                    <Ionicons
                      name={c.icon}
                      size={13}
                      color={categoryId === c.id ? '#ffffff' : Colors.textMuted}
                    />
                    <Text style={[styles.catChipText, categoryId === c.id && styles.catChipTextActive]}>
                      {c.label}
                    </Text>
                  </View>
                </TouchableOpacity>
              ))}
            </View>

            <Text style={styles.label}>Detailed Description</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              placeholder="Describe condition, history, box/papers, and authenticity..."
              placeholderTextColor={Colors.textDim}
              multiline
              numberOfLines={3}
              value={desc}
              onChangeText={setDesc}
            />

            <View style={styles.row}>
              <View style={styles.col}>
                <Text style={styles.label}>Starting Bid (PKR)</Text>
                <TextInput
                  style={styles.input}
                  placeholder="5000"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="numeric"
                  value={startingPrice}
                  onChangeText={setStartingPrice}
                />
              </View>
              <View style={styles.col}>
                <Text style={styles.label}>Reserve Price (PKR)</Text>
                <TextInput
                  style={styles.input}
                  placeholder="Optional"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="numeric"
                  value={reservePrice}
                  onChangeText={setReservePrice}
                />
              </View>
            </View>

            <View style={styles.row}>
              <View style={styles.col}>
                <Text style={styles.label}>Min Increment (PKR)</Text>
                <TextInput
                  style={styles.input}
                  placeholder="500"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="numeric"
                  value={minIncrement}
                  onChangeText={setMinIncrement}
                />
              </View>
              <View style={styles.col}>
                <Text style={styles.label}>Duration (Days)</Text>
                <TextInput
                  style={styles.input}
                  placeholder="3"
                  placeholderTextColor={Colors.textDim}
                  keyboardType="numeric"
                  value={durationDays}
                  onChangeText={setDurationDays}
                />
              </View>
            </View>

            <TouchableOpacity style={styles.publishBtn} onPress={handleCreate} disabled={submitting}>
              {submitting ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                  <Ionicons name="pricetag-outline" size={16} color="#ffffff" />
                  <Text style={styles.publishBtnText}>Publish Auction Lot</Text>
                </View>
              )}
            </TouchableOpacity>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

// ─── 4. Edit Auction Modal ────────────────────────────────────
export const EditAuctionModal: React.FC<{
  visible: boolean;
  auction: any;
  onClose: () => void;
  onSuccess: () => void;
}> = ({ visible, auction, onClose, onSuccess }) => {
  const [title, setTitle] = useState('');
  const [desc, setDesc] = useState('');
  const [reservePrice, setReservePrice] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (auction) {
      setTitle(auction.title || '');
      setDesc(auction.description || '');
      setReservePrice(auction.reserve_price ? String(auction.reserve_price) : '');
    }
  }, [auction]);

  const handleUpdate = async () => {
    if (!title.trim()) {
      Alert.alert('Required', 'Item title cannot be empty.');
      return;
    }

    setSubmitting(true);
    try {
      await MobileApi.updateAuction(auction.auction_id, {
        title: title.trim(),
        description: desc.trim(),
        reserve_price: reservePrice ? parseFloat(reservePrice) : null,
      });
      Alert.alert('Updated! ✓', 'Auction listing details have been updated.');
      onSuccess();
      onClose();
    } catch (err: any) {
      Alert.alert('Update Error', err.message || 'Could not update auction');
    } finally {
      setSubmitting(false);
    }
  };

  if (!visible || !auction) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.modalOverlay}>
        <View style={styles.modalSheet}>
          <View style={styles.modalHeader}>
            <Text style={styles.modalTitle}>Edit Lot #{auction.auction_id}</Text>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.modalBody}>
            <Text style={styles.label}>Title</Text>
            <TextInput style={styles.input} value={title} onChangeText={setTitle} />

            <Text style={styles.label}>Description</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              value={desc}
              onChangeText={setDesc}
              multiline
              numberOfLines={3}
            />

            <Text style={styles.label}>Reserve Price (PKR)</Text>
            <TextInput
              style={styles.input}
              placeholder="Optional"
              placeholderTextColor={Colors.textDim}
              keyboardType="numeric"
              value={reservePrice}
              onChangeText={setReservePrice}
            />

            <TouchableOpacity style={styles.publishBtn} onPress={handleUpdate} disabled={submitting}>
              {submitting ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                  <Ionicons name="checkmark-circle-outline" size={16} color="#ffffff" />
                  <Text style={styles.publishBtnText}>Save Changes</Text>
                </View>
              )}
            </TouchableOpacity>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

// ─── 5. Auction Analytics Modal ───────────────────────────────
export const SellerAnalyticsModal: React.FC<{
  visible: boolean;
  onClose: () => void;
}> = ({ visible, onClose }) => {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [days, setDays] = useState(7);

  const fetchAnalytics = async () => {
    setLoading(true);
    try {
      const res = await MobileApi.getSellerAnalytics(days);
      setData(res.data);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (visible) fetchAnalytics();
  }, [visible, days]);

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.modalOverlay}>
        <View style={styles.modalSheet}>
          <View style={styles.modalHeader}>
            <View>
              <Text style={styles.modalTitle}>Auction Analytics</Text>
              <Text style={styles.modalSubtitle}>Impressions, Bid Flow & Conversion</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          {/* Timeframe Chips */}
          <View style={styles.timeframeRow}>
            {[7, 14, 30].map((d) => (
              <TouchableOpacity
                key={d}
                style={[styles.timeChip, days === d && styles.timeChipActive]}
                onPress={() => setDays(d)}
              >
                <Text style={[styles.timeChipText, days === d && styles.timeChipTextActive]}>
                  Last {d} Days
                </Text>
              </TouchableOpacity>
            ))}
          </View>

          {loading ? (
            <ActivityIndicator size="large" color={Colors.primary} style={{ marginVertical: 40 }} />
          ) : (
            <ScrollView showsVerticalScrollIndicator={false} style={styles.modalBody}>
              {/* Analytics Metric Cards */}
              <View style={styles.metricsGrid}>
                <View style={styles.metricCard}>
                  <Text style={styles.metricNum}>{data?.views || 0}</Text>
                  <Text style={styles.metricLbl}>Total Views</Text>
                </View>
                <View style={styles.metricCard}>
                  <Text style={styles.metricNum}>{data?.watchlist_count || 0}</Text>
                  <Text style={styles.metricLbl}>Watchlist Saves</Text>
                </View>
                <View style={styles.metricCard}>
                  <Text style={styles.metricNum}>{data?.bids || 0}</Text>
                  <Text style={styles.metricLbl}>Total Bids</Text>
                </View>
                <View style={styles.metricCard}>
                  <Text style={styles.metricNum}>{data?.conversion_rate || 0}%</Text>
                  <Text style={styles.metricLbl}>Conversion</Text>
                </View>
              </View>

              {/* Financial Performance */}
              <View style={styles.analyticsFinanceCard}>
                <Text style={styles.financeTitle}>Revenue & Highest Bid</Text>
                <View style={styles.financeRow}>
                  <Text style={styles.financeLabel}>Highest Bid Recorded:</Text>
                  <Text style={styles.financeVal}>PKR {Number(data?.highest_bid || 0).toLocaleString()}</Text>
                </View>
                <View style={styles.financeRow}>
                  <Text style={styles.financeLabel}>Total Sales Revenue:</Text>
                  <Text style={[styles.financeVal, { color: Colors.success }]}>
                    PKR {Number(data?.revenue || 0).toLocaleString()}
                  </Text>
                </View>
                <View style={styles.financeRow}>
                  <Text style={styles.financeLabel}>Currently Held in Escrow:</Text>
                  <Text style={[styles.financeVal, { color: Colors.warning }]}>
                    PKR {Number(data?.escrow_held || 0).toLocaleString()}
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

// ─── 6. Seller Orders Screen ──────────────────────────────────
export const SellerOrdersScreen: React.FC<{
  onOpenShipment: (order: any) => void;
}> = ({ onOpenShipment }) => {
  const [orders, setOrders] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const loadOrders = async () => {
    try {
      const res = await MobileApi.getSellerOrders();
      setOrders(res.data || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadOrders();
  }, []);

  return (
    <View style={styles.container}>
      <Text style={styles.screenTitle}>Fulfillment Orders ({orders.length})</Text>

      {loading ? (
        <LoadingSkeleton count={2} />
      ) : orders.length === 0 ? (
        <EmptyState
          iconName="cube-outline"
          title="No Fulfillment Orders"
          subtitle="Orders waiting for packing and courier pickup will appear here."
        />
      ) : (
        <ScrollView showsVerticalScrollIndicator={false}>
          {orders.map((o) => (
            <View key={o.order_id} style={styles.listingCard}>
              <View style={styles.listingHeader}>
                <Text style={styles.listingTitle}>Order #{o.order_id}</Text>
                <Text
                  style={[
                    styles.statusTag,
                    o.shipping_status === 'shipped' ? styles.statusActive : styles.statusPending,
                  ]}
                >
                  {(o.shipping_status || 'PENDING').toUpperCase()}
                </Text>
              </View>
              <Text style={styles.listingPrice}>{o.auction_title || 'Item Lot'}</Text>
              <Text style={styles.listingMeta}>
                Buyer: {o.buyer_name || 'Verified Buyer'} • Amount: PKR {Number(o.total_amount).toLocaleString()}
              </Text>
              <Text style={styles.listingMeta}>
                Address: {o.shipping_address || 'Pakistan'}
              </Text>

              {o.shipping_status !== 'shipped' && o.shipping_status !== 'delivered' && (
                <TouchableOpacity
                  style={styles.shipBtn}
                  onPress={() => onOpenShipment(o)}
                  activeOpacity={0.85}
                >
                  <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                    <Ionicons name="car-sport-outline" size={15} color="#ffffff" />
                    <Text style={styles.shipBtnText}>Dispatch via Courier →</Text>
                  </View>
                </TouchableOpacity>
              )}
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
};

// ─── 7. Shipping Fulfillment Modal ────────────────────────────
export const ShippingModal: React.FC<{
  visible: boolean;
  order: any;
  onClose: () => void;
  onSuccess: () => void;
}> = ({ visible, order, onClose, onSuccess }) => {
  const [courier, setCourier] = useState('TCS');
  const [trackingNumber, setTrackingNumber] = useState('');
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleCreateShipment = async () => {
    if (!trackingNumber.trim()) {
      Alert.alert('Required', 'Please enter courier tracking number (e.g. 7721984210).');
      return;
    }

    setSubmitting(true);
    try {
      await MobileApi.createShipment(order.order_id, {
        courier,
        tracking_number: trackingNumber.trim(),
        note: note.trim() || undefined,
      });

      NotificationService.triggerShipping(order.order_id, courier, trackingNumber.trim());
      Alert.alert('Shipment Logged! 🚚', `Order #${order.order_id} marked as dispatched via ${courier}.`);
      onSuccess();
      onClose();
    } catch (err: any) {
      Alert.alert('Shipping Error', err.message || 'Could not record shipment');
    } finally {
      setSubmitting(false);
    }
  };

  if (!visible || !order) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.modalOverlay}>
        <View style={styles.modalSheet}>
          <View style={styles.modalHeader}>
            <View>
              <Text style={styles.modalTitle}>Dispatch Order #{order.order_id}</Text>
              <Text style={styles.modalSubtitle}>Pakistani Insured Courier Integration</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.modalBody}>
            <Text style={styles.label}>Select Courier Partner</Text>
            <View style={styles.courierRow}>
              {['TCS', 'Leopards', 'Trax', 'M&P'].map((c) => (
                <TouchableOpacity
                  key={c}
                  style={[styles.courierChip, courier === c && styles.courierChipActive]}
                  onPress={() => setCourier(c)}
                >
                  <Text style={[styles.courierText, courier === c && styles.courierTextActive]}>
                    {c}
                  </Text>
                </TouchableOpacity>
              ))}
            </View>

            <Text style={styles.label}>Airway Bill / Tracking Number</Text>
            <TextInput
              style={styles.input}
              placeholder="e.g. 77219830129"
              placeholderTextColor={Colors.textDim}
              value={trackingNumber}
              onChangeText={setTrackingNumber}
            />

            <Text style={styles.label}>Packaging & Delivery Note</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              placeholder="e.g. Bubble wrapped, fragile glass handling required"
              placeholderTextColor={Colors.textDim}
              value={note}
              onChangeText={setNote}
            />

            <TouchableOpacity
              style={styles.publishBtn}
              onPress={handleCreateShipment}
              disabled={submitting}
            >
              {submitting ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                  <Ionicons name="car-sport-outline" size={16} color="#ffffff" />
                  <Text style={styles.publishBtnText}>Confirm Courier Dispatch</Text>
                </View>
              )}
            </TouchableOpacity>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

// ─── 8. Seller Payouts & Earnings View ────────────────────────
export const SellerPayoutsScreen: React.FC = () => {
  const [data, setData] = useState<any>(null);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<'bank_transfer' | 'jazzcash' | 'easypaisa'>('jazzcash');
  const [accountNumber, setAccountNumber] = useState('');
  const [accountTitle, setAccountTitle] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [loading, setLoading] = useState(true);

  const loadPayouts = async () => {
    try {
      const res = await MobileApi.getSellerPayouts();
      setData(res.data);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadPayouts();
  }, []);

  const handleRequestPayout = async () => {
    const val = parseFloat(amount);
    if (isNaN(val) || val < 1000) {
      Alert.alert('Invalid Amount', 'Minimum withdrawal request is PKR 1,000.');
      return;
    }
    if (!accountNumber.trim() || !accountTitle.trim()) {
      Alert.alert('Required', 'Please enter account title and account/mobile number.');
      return;
    }

    setSubmitting(true);
    try {
      await MobileApi.requestSellerPayout({
        amount: val,
        method,
        account_title: accountTitle.trim(),
        account_number: accountNumber.trim(),
      });

      NotificationService.notify({
        type: 'payment',
        title: '💰 Payout Request Submitted',
        message: `Withdrawal of PKR ${val.toLocaleString()} queued for bank transfer.`,
      });

      Alert.alert('Payout Requested! 💰', 'Your withdrawal request has been submitted for batch processing.');
      setAmount('');
      loadPayouts();
    } catch (err: any) {
      Alert.alert('Payout Error', err.message || 'Failed to submit withdrawal');
    } finally {
      setSubmitting(false);
    }
  };

  const available = data?.available_balance || 0;
  const history = data?.payouts || [];

  return (
    <ScrollView style={styles.container} showsVerticalScrollIndicator={false}>
      {/* Earnings Summary Card */}
      <View style={styles.walletCard}>
        <Text style={styles.walletTag}>AVAILABLE SELLER BALANCE</Text>
        <Text style={styles.walletAmount}>PKR {Number(available).toLocaleString()}</Text>
        <Text style={styles.walletSub}>Disbursements processed in 24 business hours</Text>
      </View>

      {/* Payout Request Form */}
      <View style={styles.formCard}>
        <Text style={styles.formTitle}>Request Funds Withdrawal</Text>

        <Text style={styles.label}>Withdrawal Amount (Min. PKR 1,000)</Text>
        <TextInput
          style={styles.input}
          placeholder="e.g. 15000"
          placeholderTextColor={Colors.textDim}
          keyboardType="numeric"
          value={amount}
          onChangeText={setAmount}
        />

        <Text style={styles.label}>Destination Rail</Text>
        <View style={styles.railRow}>
          <TouchableOpacity
            style={[styles.railBtn, method === 'jazzcash' && styles.railBtnActive]}
            onPress={() => setMethod('jazzcash')}
          >
            <Text style={styles.railBtnText}>JazzCash</Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.railBtn, method === 'easypaisa' && styles.railBtnActive]}
            onPress={() => setMethod('easypaisa')}
          >
            <Text style={styles.railBtnText}>EasyPaisa</Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.railBtn, method === 'bank_transfer' && styles.railBtnActive]}
            onPress={() => setMethod('bank_transfer')}
          >
            <Text style={styles.railBtnText}>Bank Wire</Text>
          </TouchableOpacity>
        </View>

        <Text style={styles.label}>Account Title</Text>
        <TextInput
          style={styles.input}
          placeholder="e.g. Muhammad Ali"
          placeholderTextColor={Colors.textDim}
          value={accountTitle}
          onChangeText={setAccountTitle}
        />

        <Text style={styles.label}>Account Number / IBAN</Text>
        <TextInput
          style={styles.input}
          placeholder="0300-1234567 or PK36MEZN..."
          placeholderTextColor={Colors.textDim}
          value={accountNumber}
          onChangeText={setAccountNumber}
        />

        <TouchableOpacity
          style={styles.submitBtn}
          onPress={handleRequestPayout}
          disabled={submitting}
        >
          {submitting ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
              <Ionicons name="wallet-outline" size={16} color="#ffffff" />
              <Text style={styles.submitBtnText}>Submit Payout Request</Text>
            </View>
          )}
        </TouchableOpacity>
      </View>

      {/* History Ledger */}
      <Text style={styles.sectionHeading}>Withdrawal Ledger ({history.length})</Text>
      {history.map((h: any) => (
        <View key={h.payout_id} style={styles.payoutHistoryRow}>
          <View>
            <Text style={styles.payoutRef}>#{h.reference || h.payout_id}</Text>
            <Text style={styles.payoutMethod}>{h.method?.toUpperCase()}</Text>
          </View>
          <View style={{ alignItems: 'flex-end' }}>
            <Text style={styles.payoutAmt}>PKR {Number(h.amount).toLocaleString()}</Text>
            <Text
              style={[
                styles.statusTag,
                h.status === 'completed' ? styles.statusActive : styles.statusPending,
              ]}
            >
              {(h.status || 'PENDING').toUpperCase()}
            </Text>
          </View>
        </View>
      ))}
    </ScrollView>
  );
};

// ─── 9. Seller KYC Modal ──────────────────────────────────────
export const SellerKycModal: React.FC<{
  visible: boolean;
  onClose: () => void;
}> = ({ visible, onClose }) => {
  const { user, refreshProfile } = useAuth();
  const [fullName, setFullName] = useState(user?.full_name || '');
  const [cnic, setCnic] = useState('');
  const [dob, setDob] = useState('1990-01-01');
  const [bankName, setBankName] = useState('Meezan Bank');
  const [accountHolder, setAccountHolder] = useState(user?.full_name || '');
  const [accountNumber, setAccountNumber] = useState('');
  const [iban, setIban] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async () => {
    const rawCnic = cnic.replace(/\D/g, '');
    if (rawCnic.length !== 13) {
      Alert.alert('Invalid CNIC', 'CNIC must be 13 digits.');
      return;
    }
    if (!bankName || !accountNumber) {
      Alert.alert('Required', 'Bank account details are required.');
      return;
    }

    setSubmitting(true);
    try {
      await MobileApi.submitSellerKyc({
        full_name_on_cnic: fullName,
        cnic_number: rawCnic,
        date_of_birth: dob,
        bank_name: bankName,
        bank_account_holder: accountHolder,
        bank_account_number: accountNumber,
        bank_iban: iban || null,
      });
      Alert.alert('Seller KYC Submitted! 🪪', 'Your merchant identity and settlement bank have been registered for compliance review.');
      await refreshProfile();
      onClose();
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Failed to submit seller KYC');
    } finally {
      setSubmitting(false);
    }
  };

  if (!visible) return null;

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.modalOverlay}>
        <View style={styles.modalSheet}>
          <View style={styles.modalHeader}>
            <View>
              <Text style={styles.modalTitle}>Merchant Seller KYC</Text>
              <Text style={styles.modalSubtitle}>NADRA + Pakistani Banking Compliance</Text>
            </View>
            <TouchableOpacity onPress={onClose} style={styles.closeBtn} activeOpacity={0.7}>
              <Ionicons name="close" size={18} color={Colors.textMuted} />
            </TouchableOpacity>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} style={styles.modalBody}>
            <Text style={styles.label}>Legal Name on CNIC</Text>
            <TextInput style={styles.input} value={fullName} onChangeText={setFullName} />

            <Text style={styles.label}>13-Digit CNIC Number</Text>
            <TextInput style={styles.input} placeholder="35201-XXXXXXX-X" placeholderTextColor={Colors.textDim} keyboardType="numeric" value={cnic} onChangeText={setCnic} />

            <Text style={styles.label}>Settlement Bank Name</Text>
            <TextInput style={styles.input} placeholder="e.g. Meezan Bank / HBL / Alfalah" placeholderTextColor={Colors.textDim} value={bankName} onChangeText={setBankName} />

            <Text style={styles.label}>Bank Account Holder Name</Text>
            <TextInput style={styles.input} value={accountHolder} onChangeText={setAccountHolder} />

            <Text style={styles.label}>Account Number</Text>
            <TextInput style={styles.input} placeholder="0101-01020304" placeholderTextColor={Colors.textDim} value={accountNumber} onChangeText={setAccountNumber} />

            <Text style={styles.label}>IBAN (Optional)</Text>
            <TextInput style={styles.input} placeholder="PK36MEZN0000000..." placeholderTextColor={Colors.textDim} value={iban} onChangeText={setIban} />

            <TouchableOpacity style={styles.publishBtn} onPress={handleSubmit} disabled={submitting}>
              {submitting ? (
                <ActivityIndicator color="#fff" />
              ) : (
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                  <Ionicons name="shield-checkmark" size={16} color="#ffffff" />
                  <Text style={styles.publishBtnText}>Submit Merchant KYC</Text>
                </View>
              )}
            </TouchableOpacity>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};

// ─── 10. Seller Reviews & Feedback Screen ─────────────────────
export const SellerReviewsScreen: React.FC = () => {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [replyText, setReplyText] = useState('');
  const [replyingReviewId, setReplyingReviewId] = useState<number | null>(null);

  const loadReviews = async () => {
    try {
      const res = await MobileApi.getSellerReviews();
      setData(res.data);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadReviews();
  }, []);

  const handlePostReply = async (reviewId: number) => {
    if (!replyText.trim()) return;
    try {
      await MobileApi.replySellerReview(reviewId, replyText.trim());
      Alert.alert('Replied! ✓', 'Your response has been published.');
      setReplyingReviewId(null);
      setReplyText('');
      loadReviews();
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Could not post reply');
    }
  };

  const reviews = data?.reviews || [];

  return (
    <View style={styles.container}>
      <Text style={styles.screenTitle}>Merchant Reviews & Ratings</Text>

      {/* Rating Banner */}
      <View style={styles.ratingHero}>
        <Text style={styles.ratingScore}>{data?.average_rating || '5.0'}</Text>
        <Text style={styles.ratingStars}>★★★★★</Text>
        <Text style={styles.ratingCount}>Based on {data?.total_reviews || reviews.length} verified buyer transactions</Text>
      </View>

      {loading ? (
        <LoadingSkeleton count={2} />
      ) : reviews.length === 0 ? (
        <EmptyState
          icon="⭐"
          title="No Reviews Yet"
          subtitle="Customer ratings will be logged after orders are delivered."
        />
      ) : (
        <ScrollView showsVerticalScrollIndicator={false}>
          {reviews.map((r: any) => (
            <View key={r.review_id} style={styles.reviewCard}>
              <View style={styles.reviewHeader}>
                <Text style={styles.reviewerName}>{r.buyer_name || 'Verified Buyer'}</Text>
                <Text style={styles.reviewRating}>{'★'.repeat(r.rating || 5)}</Text>
              </View>
              <Text style={styles.reviewLot}>Order #{r.order_id} • {r.auction_title || 'Lot Item'}</Text>
              <Text style={styles.reviewComment}>{r.comment || 'Great experience!'}</Text>

              {r.seller_reply ? (
                <View style={styles.sellerReplyBox}>
                  <Text style={styles.sellerReplyLabel}>Merchant Reply:</Text>
                  <Text style={styles.sellerReplyText}>{r.seller_reply}</Text>
                </View>
              ) : replyingReviewId === r.review_id ? (
                <View style={styles.replyInputBox}>
                  <TextInput
                    style={styles.input}
                    placeholder="Write polite public reply..."
                    placeholderTextColor={Colors.textDim}
                    value={replyText}
                    onChangeText={setReplyText}
                  />
                  <TouchableOpacity
                    style={styles.replySubmitBtn}
                    onPress={() => handlePostReply(r.review_id)}
                  >
                    <Text style={styles.replySubmitText}>Send Reply</Text>
                  </TouchableOpacity>
                </View>
              ) : (
                <TouchableOpacity
                  style={styles.replyPromptBtn}
                  onPress={() => setReplyingReviewId(r.review_id)}
                >
                  <Text style={styles.replyPromptText}>↩ Reply to Buyer</Text>
                </TouchableOpacity>
              )}
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  // ─── Layout ────────────────────────────────────────────────────
  container: {
    flex: 1,
    backgroundColor: Colors.background,
    padding: 16,
  },

  // ─── Wallet / Balance Hero Card ────────────────────────────────
  walletCard: {
    backgroundColor: '#0E1726',
    borderRadius: 18,
    borderWidth: 1,
    borderColor: 'rgba(81, 69, 229, 0.4)',
    padding: 20,
    marginBottom: 20,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 10,
    elevation: 6,
  },
  walletHeaderRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 6,
  },
  walletTag: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.primaryLight,
    letterSpacing: 0.8,
  },
  kycActiveBadge: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.success,
  },
  kycWarnBadge: {
    backgroundColor: Colors.warningLight,
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
  },
  kycWarnText: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.warning,
  },
  walletAmount: {
    fontSize: 28,
    fontWeight: '900',
    color: '#FFFFFF',
    marginBottom: 4,
  },
  walletSub: {
    fontSize: 11,
    color: 'rgba(255,255,255,0.65)',
    marginBottom: 16,
  },
  walletRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: 'rgba(255,255,255,0.15)',
  },
  miniBal: {
    flex: 1,
  },
  miniBalLbl: {
    fontSize: 10,
    color: 'rgba(255,255,255,0.6)',
  },
  miniBalVal: {
    fontSize: 14,
    fontWeight: '700',
    color: Colors.primaryLight,
  },
  payoutBtn: {
    backgroundColor: Colors.primary,
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 10,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.3,
    shadowRadius: 4,
    elevation: 3,
  },
  payoutBtnText: {
    fontSize: 12,
    fontWeight: '800',
    color: '#FFFFFF',
  },

  // ─── Stats Grid ────────────────────────────────────────────────
  statsGrid: {
    flexDirection: 'row',
    gap: 10,
    marginBottom: 20,
  },
  statBox: {
    flex: 1,
    backgroundColor: Colors.card,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    padding: 14,
    alignItems: 'center',
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 6,
    elevation: 2,
  },
  statIcon: {
    fontSize: 22,
    marginBottom: 4,
  },
  statVal: {
    fontSize: 18,
    fontWeight: '900',
    color: Colors.text,
  },
  statLbl: {
    fontSize: 10,
    color: Colors.textMuted,
    marginTop: 2,
    textAlign: 'center',
  },

  // ─── Create Auction CTA ────────────────────────────────────────
  createBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: 'rgba(81, 69, 229, 0.12)',
    borderWidth: 1.5,
    borderColor: Colors.primary,
    borderRadius: 14,
    padding: 16,
    marginBottom: 14,
  },
  createBtnIconCircle: {
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: Colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 12,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.3,
    shadowRadius: 4,
    elevation: 3,
  },
  createBtnIcon: {
    fontSize: 24,
    marginRight: 14,
  },
  createBtnTextWrap: {
    flex: 1,
  },
  createBtnTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: Colors.text,
  },
  createBtnSub: {
    fontSize: 11,
    color: Colors.textMuted,
    marginTop: 1,
  },
  createBtnArrow: {
    fontSize: 18,
    color: Colors.primaryLight,
    fontWeight: '800',
  },

  // ─── Analytics Shortcut ────────────────────────────────────────
  analyticsShortcut: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 24,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 5,
    elevation: 2,
  },
  analyticsIconCircle: {
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: Colors.badgeBg,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 12,
  },
  analyticsIcon: {
    fontSize: 24,
    marginRight: 14,
  },
  analyticsTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
  },
  analyticsSub: {
    fontSize: 11,
    color: Colors.textMuted,
    marginTop: 2,
  },
  analyticsArrow: {
    fontSize: 16,
    color: Colors.textDim,
  },

  // ─── Section Header / Screen Title ────────────────────────────
  sectionHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 12,
  },
  screenTitle: {
    fontSize: 16,
    fontWeight: '800',
    color: Colors.text,
  },
  addMiniBtn: {
    backgroundColor: Colors.primary,
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 8,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 3,
    elevation: 2,
  },
  addMiniText: {
    fontSize: 12,
    fontWeight: '700',
    color: '#FFFFFF',
  },

  // ─── Filter Bar (Listings) ─────────────────────────────────────
  filterBar: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 14,
  },
  filterChip: {
    backgroundColor: Colors.surface,
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  filterChipActive: {
    backgroundColor: Colors.primary,
    borderColor: Colors.primary,
  },
  filterChipText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.textMuted,
  },
  filterChipTextActive: {
    color: '#FFFFFF',
  },

  // ─── Listing / Order Cards ─────────────────────────────────────
  listingCard: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    padding: 14,
    marginBottom: 10,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 5,
    elevation: 2,
  },
  listingHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 6,
  },
  listingTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: Colors.text,
    flex: 1,
    marginRight: 10,
  },

  // ─── Status Tags ───────────────────────────────────────────────
  statusTag: {
    fontSize: 10,
    fontWeight: '800',
    paddingHorizontal: 7,
    paddingVertical: 3,
    borderRadius: 5,
    overflow: 'hidden',
  },
  statusActive: {
    backgroundColor: Colors.successLight,
    color: Colors.success,
  },
  statusEnded: {
    backgroundColor: 'rgba(112, 122, 144, 0.15)',
    color: Colors.textDim,
  },
  statusPending: {
    backgroundColor: Colors.warningLight,
    color: Colors.warning,
  },

  listingPrice: {
    fontSize: 13,
    color: Colors.primaryLight,
    fontWeight: '700',
    marginBottom: 4,
  },
  listingMeta: {
    fontSize: 11,
    color: Colors.textDim,
    marginBottom: 4,
  },
  listingActionsRow: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    marginTop: 8,
  },
  editListingBtn: {
    backgroundColor: Colors.surfaceLight,
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 7,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  editListingText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.primaryLight,
  },

  // ─── Ship Button ───────────────────────────────────────────────
  shipBtn: {
    backgroundColor: Colors.primary,
    paddingVertical: 11,
    borderRadius: 9,
    alignItems: 'center',
    marginTop: 10,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 4,
    elevation: 3,
  },
  shipBtnText: {
    fontSize: 12,
    fontWeight: '800',
    color: '#FFFFFF',
  },

  // ─── Modal Overlay & Sheet ─────────────────────────────────────
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(3, 8, 23, 0.82)',
    justifyContent: 'flex-end',
  },
  modalSheet: {
    backgroundColor: Colors.background,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    maxHeight: '92%',
    padding: 18,
    borderTopWidth: 1,
    borderColor: Colors.border,
  },
  modalHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 16,
  },
  modalTitle: {
    fontSize: 17,
    fontWeight: '800',
    color: Colors.text,
  },
  modalSubtitle: {
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
    color: Colors.textMuted,
    fontSize: 15,
    fontWeight: '700',
  },
  modalBody: {
    marginBottom: 20,
  },

  // ─── Form Labels & Inputs ──────────────────────────────────────
  label: {
    fontSize: 13,
    fontWeight: '600',
    color: Colors.text,
    marginBottom: 6,
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
    minHeight: 48,
  },
  textArea: {
    minHeight: 80,
    textAlignVertical: 'top',
  },
  row: {
    flexDirection: 'row',
    gap: 10,
  },
  col: {
    flex: 1,
  },

  // ─── Category Chips ────────────────────────────────────────────
  catChipsRow: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 14,
  },
  catChip: {
    flex: 1,
    backgroundColor: Colors.surface,
    paddingVertical: 9,
    borderRadius: 9,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: Colors.border,
  },
  catChipActive: {
    borderColor: Colors.primary,
    backgroundColor: Colors.primary,
  },
  catChipText: {
    fontSize: 11,
    color: Colors.textDim,
    fontWeight: '700',
  },
  catChipTextActive: {
    color: '#FFFFFF',
  },

  // ─── Courier Chips (ShippingModal) ─────────────────────────────
  courierRow: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 14,
  },
  courierChip: {
    flex: 1,
    backgroundColor: Colors.surface,
    paddingVertical: 11,
    borderRadius: 9,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: Colors.border,
  },
  courierChipActive: {
    borderColor: Colors.primary,
    backgroundColor: 'rgba(81, 69, 229, 0.15)',
  },
  courierText: {
    fontSize: 12,
    color: Colors.textMuted,
    fontWeight: '700',
  },
  courierTextActive: {
    color: Colors.primaryLight,
  },

  // ─── Publish / Submit Buttons ──────────────────────────────────
  publishBtn: {
    backgroundColor: Colors.primary,
    height: 52,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 10,
    marginBottom: 16,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 3 },
    shadowOpacity: 0.3,
    shadowRadius: 6,
    elevation: 4,
  },
  publishBtnText: {
    fontSize: 15,
    fontWeight: '800',
    color: '#FFFFFF',
    letterSpacing: 0.3,
  },

  // ─── Analytics Timeframe Chips ─────────────────────────────────
  timeframeRow: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 14,
  },
  timeChip: {
    backgroundColor: Colors.surface,
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  timeChipActive: {
    backgroundColor: Colors.primary,
    borderColor: Colors.primary,
  },
  timeChipText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.textMuted,
  },
  timeChipTextActive: {
    color: '#FFFFFF',
  },

  // ─── Analytics Metric Cards ────────────────────────────────────
  metricsGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
    marginBottom: 16,
  },
  metricCard: {
    width: '48%',
    backgroundColor: Colors.card,
    borderRadius: 12,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    borderTopWidth: 3,
    borderTopColor: Colors.primary,
    alignItems: 'center',
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 5,
    elevation: 2,
  },
  metricNum: {
    fontSize: 24,
    fontWeight: '900',
    color: Colors.text,
  },
  metricLbl: {
    fontSize: 10,
    color: Colors.textMuted,
    marginTop: 3,
    textAlign: 'center',
  },

  // ─── Analytics Finance Card ────────────────────────────────────
  analyticsFinanceCard: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 20,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 5,
    elevation: 2,
  },
  financeTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 10,
  },
  financeRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 7,
    borderBottomWidth: 1,
    borderBottomColor: Colors.borderSubtle,
  },
  financeLabel: {
    fontSize: 11,
    color: Colors.textMuted,
  },
  financeVal: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.text,
  },

  // ─── Payouts Form Card ─────────────────────────────────────────
  formCard: {
    backgroundColor: Colors.card,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    padding: 16,
    marginBottom: 20,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 6,
    elevation: 2,
  },
  formTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 14,
  },

  // ─── Payout Rail Selector ──────────────────────────────────────
  railRow: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 14,
  },
  railBtn: {
    flex: 1,
    paddingVertical: 9,
    backgroundColor: Colors.surface,
    borderRadius: 9,
    borderWidth: 1,
    borderColor: Colors.border,
    alignItems: 'center',
  },
  railBtnActive: {
    borderColor: Colors.primary,
    backgroundColor: 'rgba(81, 69, 229, 0.15)',
  },
  railBtnText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.text,
  },

  // ─── Submit / Withdraw Button ──────────────────────────────────
  submitBtn: {
    backgroundColor: Colors.primary,
    paddingVertical: 13,
    borderRadius: 11,
    alignItems: 'center',
    marginTop: 6,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 3 },
    shadowOpacity: 0.3,
    shadowRadius: 5,
    elevation: 3,
  },
  submitBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: '#FFFFFF',
  },

  // ─── Payout History Ledger ─────────────────────────────────────
  sectionHeading: {
    fontSize: 14,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 10,
  },
  payoutHistoryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: Colors.card,
    padding: 13,
    borderRadius: 11,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 8,
  },
  payoutRef: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.text,
  },
  payoutMethod: {
    fontSize: 10,
    color: Colors.textDim,
    marginTop: 1,
  },
  payoutAmt: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.success,
  },

  // ─── Reviews: Rating Hero ──────────────────────────────────────
  ratingHero: {
    backgroundColor: Colors.card,
    borderRadius: 16,
    padding: 22,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 16,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 6,
    elevation: 2,
  },
  ratingScore: {
    fontSize: 40,
    fontWeight: '900',
    color: Colors.text,
  },
  ratingStars: {
    fontSize: 22,
    color: Colors.gold,
    marginVertical: 4,
  },
  ratingCount: {
    fontSize: 11,
    color: Colors.textMuted,
  },

  // ─── Review Cards ──────────────────────────────────────────────
  reviewCard: {
    backgroundColor: Colors.card,
    borderRadius: 13,
    padding: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 10,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 4,
    elevation: 1,
  },
  reviewHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 4,
  },
  reviewerName: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
  },
  reviewRating: {
    color: Colors.gold,
    fontSize: 15,
  },
  reviewLot: {
    fontSize: 10,
    color: Colors.textDim,
    marginBottom: 6,
  },
  reviewComment: {
    fontSize: 12,
    color: Colors.textMuted,
    lineHeight: 17,
  },

  // ─── Seller Reply Box ──────────────────────────────────────────
  sellerReplyBox: {
    backgroundColor: 'rgba(81, 69, 229, 0.12)',
    borderRadius: 8,
    padding: 10,
    marginTop: 8,
    borderLeftWidth: 3,
    borderLeftColor: Colors.primary,
  },
  sellerReplyLabel: {
    fontSize: 10,
    fontWeight: '800',
    color: Colors.primaryLight,
    marginBottom: 2,
  },
  sellerReplyText: {
    fontSize: 11,
    color: Colors.text,
  },
  replyPromptBtn: {
    alignSelf: 'flex-start',
    marginTop: 8,
  },
  replyPromptText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.primaryLight,
  },
  replyInputBox: {
    marginTop: 8,
  },
  replySubmitBtn: {
    backgroundColor: Colors.primary,
    paddingVertical: 9,
    borderRadius: 7,
    alignItems: 'center',
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.3,
    shadowRadius: 3,
    elevation: 2,
  },
  replySubmitText: {
    fontSize: 11,
    fontWeight: '800',
    color: '#FFFFFF',
  },
});
