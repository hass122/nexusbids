import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  TouchableOpacity,
  TextInput,
  RefreshControl,
  FlatList,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Colors } from '../../theme/colors';
import { MobileApi } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { AuctionCard } from '../../components/AuctionCard';
import { LoadingSkeleton } from '../../components/LoadingSkeleton';
import { EmptyState } from '../../components/EmptyState';

const getCategoryIcon = (name: string): keyof typeof Ionicons.glyphMap => {
  const n = (name || '').toLowerCase();
  if (n.includes('watch') || n.includes('time')) return 'watch-outline';
  if (n.includes('elect') || n.includes('tech') || n.includes('phone') || n.includes('comp')) return 'laptop-outline';
  if (n.includes('vehic') || n.includes('car') || n.includes('auto')) return 'car-sport-outline';
  if (n.includes('collec') || n.includes('coin') || n.includes('rare')) return 'diamond-outline';
  if (n.includes('art') || n.includes('paint') || n.includes('antiq')) return 'color-palette-outline';
  if (n.includes('jewel') || n.includes('gem')) return 'sparkles-outline';
  return 'cube-outline';
};

// ─── 1. Home View ─────────────────────────────────────────────
export const HomeScreen: React.FC<{
  onSelectAuction: (id: number) => void;
  onExploreMore: (categoryId?: number) => void;
  onOpenChat: () => void;
}> = ({ onSelectAuction, onExploreMore, onOpenChat }) => {
  const [trending, setTrending] = useState<any[]>([]);
  const [categories, setCategories] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadData = async () => {
    try {
      const [trendRes, catRes] = await Promise.all([
        MobileApi.getTrendingAuctions(8),
        MobileApi.getCategories().catch(() => ({ data: [] })),
      ]);
      setTrending(trendRes.data?.trending || trendRes.data || []);
      setCategories(catRes.data || []);
    } catch (err: any) {
      console.warn('Home fetch error:', err.message);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  return (
    <ScrollView
      style={styles.container}
      showsVerticalScrollIndicator={false}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={() => {
            setRefreshing(true);
            loadData();
          }}
          tintColor={Colors.primary}
        />
      }
    >
      {/* Hero Banner with Pakistani Flag badge */}
      <View style={styles.heroBanner}>
        <View style={styles.heroBadge}>
          <Ionicons name="shield-checkmark" size={11} color={Colors.primaryLight} style={{ marginRight: 4 }} />
          <Text style={styles.heroBadgeText}>PAKISTAN'S #1 LIVE AUCTION HOUSE</Text>
        </View>
        <Text style={styles.heroTitle}>Bid on Rare Lots with 100% Escrow Protection</Text>
        <Text style={styles.heroSubtitle}>
          NADRA Biometrics • EasyPaisa • JazzCash • Insured TCS Courier
        </Text>
        <TouchableOpacity style={styles.heroBtn} onPress={() => onExploreMore()} activeOpacity={0.85}>
          <Text style={styles.heroBtnText}>Explore Live Lots →</Text>
        </TouchableOpacity>
      </View>

      {/* Categories Horizontal Carousel */}
      <View style={styles.sectionHeader}>
        <Text style={styles.sectionTitle}>Popular Categories</Text>
      </View>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.catScroll}>
        {(categories.length > 0
          ? categories
          : [
            { category_id: 1, name: 'Watches' },
            { category_id: 2, name: 'Electronics' },
            { category_id: 3, name: 'Vehicles' },
            { category_id: 4, name: 'Collectibles' },
            { category_id: 5, name: 'Art & Antiques' },
          ]
        ).map((c: any) => (
          <TouchableOpacity
            key={c.category_id || c.name}
            style={styles.catBubble}
            onPress={() => onExploreMore(c.category_id)}
            activeOpacity={0.8}
          >
            <View style={{ marginBottom: 4 }}>
              <Ionicons name={getCategoryIcon(c.name)} size={20} color={Colors.primaryLight} />
            </View>
            <Text style={styles.catName}>{c.name}</Text>
          </TouchableOpacity>
        ))}
      </ScrollView>

      {/* Trending Live Auctions */}
      <View style={styles.sectionHeader}>
        <Text style={styles.sectionTitle}>Trending Live Auctions</Text>
        <TouchableOpacity onPress={() => onExploreMore()}>
          <Text style={styles.sectionLink}>View All</Text>
        </TouchableOpacity>
      </View>

      {loading ? (
        <LoadingSkeleton count={2} />
      ) : trending.length === 0 ? (
        <EmptyState
          iconName="cube-outline"
          title="No live auctions right now"
          subtitle="No live auctions right now. Check back soon."
          buttonText="Browse Categories"
          onButtonPress={() => onExploreMore()}
        />
      ) : (
        trending.map((item) => (
          <AuctionCard
            key={item.auction_id}
            auction={item}
            onPress={() => onSelectAuction(item.auction_id)}
            onBidPress={() => onSelectAuction(item.auction_id)}
          />
        ))
      )}

      {/* AI Assistant Floating Card */}
      <TouchableOpacity style={styles.aiCard} onPress={onOpenChat} activeOpacity={0.85}>
        <View style={{ width: 36, height: 36, borderRadius: 18, backgroundColor: 'rgba(99, 102, 241, 0.15)', alignItems: 'center', justifyContent: 'center', marginRight: 12 }}>
          <Ionicons name="sparkles" size={18} color={Colors.primaryLight} />
        </View>
        <View style={styles.aiContent}>
          <Text style={styles.aiTitle}>NexusBids AI Assistant</Text>
          <Text style={styles.aiSub}>Ask questions about proxy limits, NADRA rules, or escrow</Text>
        </View>
        <Ionicons name="arrow-forward" size={16} color={Colors.primaryLight} />
      </TouchableOpacity>
    </ScrollView>
  );
};

// ─── 2. Explore / Auctions View ───────────────────────────────
export const AuctionsScreen: React.FC<{
  onSelectAuction: (id: number) => void;
  selectedCategoryId?: number | null;
}> = ({ onSelectAuction, selectedCategoryId }) => {
  const [auctions, setAuctions] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [sortBy, setSortBy] = useState<'ending' | 'bid' | 'newest'>('ending');
  const [refreshing, setRefreshing] = useState(false);

  const fetchAuctions = async () => {
    try {
      const res = await MobileApi.getAuctions({
        status: 'active',
        limit: 40,
        category_id: selectedCategoryId || undefined,
      });
      setAuctions(res.data || []);
    } catch (err: any) {
      console.warn('Auctions fetch error:', err.message);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchAuctions();
  }, [selectedCategoryId]);

  const filtered = auctions
    .filter(
      (a) =>
        a.title?.toLowerCase().includes(search.toLowerCase()) ||
        a.category_name?.toLowerCase().includes(search.toLowerCase())
    )
    .sort((a, b) => {
      if (sortBy === 'bid') {
        return (
          parseFloat(b.current_bid || b.starting_price || 0) -
          parseFloat(a.current_bid || a.starting_price || 0)
        );
      }
      if (sortBy === 'newest') {
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      }
      // Ending soonest
      return new Date(a.end_time).getTime() - new Date(b.end_time).getTime();
    });

  return (
    <View style={styles.container}>
      {/* Search Input */}
      <View style={styles.searchBar}>
        <Ionicons name="search" size={17} color={Colors.textMuted} style={{ marginRight: 8 }} />
        <TextInput
          style={styles.searchInput}
          placeholder="Search lots by keyword or brand..."
          placeholderTextColor={Colors.textDim}
          value={search}
          onChangeText={setSearch}
        />
      </View>

      {/* Sort Filter Chips */}
      <View style={styles.sortBar}>
        <TouchableOpacity
          style={[styles.sortChip, sortBy === 'ending' && styles.sortChipActive]}
          onPress={() => setSortBy('ending')}
        >
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
            <Ionicons
              name="time-outline"
              size={12}
              color={sortBy === 'ending' ? '#ffffff' : Colors.textMuted}
            />
            <Text style={[styles.sortChipText, sortBy === 'ending' && styles.sortChipTextActive]}>
              Ending Soonest
            </Text>
          </View>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.sortChip, sortBy === 'bid' && styles.sortChipActive]}
          onPress={() => setSortBy('bid')}
        >
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
            <Ionicons
              name="trending-up-outline"
              size={12}
              color={sortBy === 'bid' ? '#ffffff' : Colors.textMuted}
            />
            <Text style={[styles.sortChipText, sortBy === 'bid' && styles.sortChipTextActive]}>
              Highest Bid
            </Text>
          </View>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.sortChip, sortBy === 'newest' && styles.sortChipActive]}
          onPress={() => setSortBy('newest')}
        >
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
            <Ionicons
              name="sparkles-outline"
              size={12}
              color={sortBy === 'newest' ? '#ffffff' : Colors.textMuted}
            />
            <Text style={[styles.sortChipText, sortBy === 'newest' && styles.sortChipTextActive]}>
              New Lots
            </Text>
          </View>
        </TouchableOpacity>
      </View>

      {loading ? (
        <LoadingSkeleton count={3} />
      ) : (
        <FlatList
          data={filtered}
          keyExtractor={(item) => String(item.auction_id)}
          renderItem={({ item }) => (
            <AuctionCard
              auction={item}
              onPress={() => onSelectAuction(item.auction_id)}
              onBidPress={() => onSelectAuction(item.auction_id)}
            />
          )}
          refreshControl={
            <RefreshControl
              refreshing={refreshing}
              onRefresh={() => {
                setRefreshing(true);
                fetchAuctions();
              }}
              tintColor={Colors.primary}
            />
          }
          ListEmptyComponent={
            <EmptyState
              title="No auctions match query"
              subtitle="Try adjusting your keyword or sorting filters."
            />
          }
          showsVerticalScrollIndicator={false}
          contentContainerStyle={{ paddingBottom: 24 }}
        />
      )}
    </View>
  );
};

// ─── 3. Watchlist View ────────────────────────────────────────
export const WatchlistScreen: React.FC<{
  onSelectAuction: (id: number) => void;
  onExploreMore: () => void;
}> = ({ onSelectAuction, onExploreMore }) => {
  const { user } = useAuth();
  const [watchlist, setWatchlist] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadWatchlist = async () => {
    if (!user) {
      setLoading(false);
      return;
    }
    try {
      const res = await MobileApi.getWatchlist();
      setWatchlist(res.data || []);
    } catch {
      setWatchlist([]);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    loadWatchlist();
  }, [user]);

  const handleRemove = async (auctionId: number) => {
    try {
      await MobileApi.removeFromWatchlist(auctionId);
      setWatchlist((prev) => prev.filter((a) => a.auction_id !== auctionId));
    } catch { }
  };

  if (!user) {
    return (
      <View style={styles.container}>
        <EmptyState
          iconName="heart-outline"
          title="Sign In Required"
          subtitle="Sign in to save lots and receive instant outbid push alerts."
        />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Text style={styles.screenHeader}>My Watchlist ({watchlist.length})</Text>
      {loading ? (
        <LoadingSkeleton count={2} />
      ) : watchlist.length === 0 ? (
        <EmptyState
          iconName="heart-outline"
          title="Your watchlist is empty"
          subtitle="Your watchlist is empty. Save items you want to follow."
          buttonText="Browse Auctions"
          onButtonPress={onExploreMore}
        />
      ) : (
        <ScrollView
          showsVerticalScrollIndicator={false}
          refreshControl={
            <RefreshControl
              refreshing={refreshing}
              onRefresh={() => {
                setRefreshing(true);
                loadWatchlist();
              }}
              tintColor={Colors.primary}
            />
          }
        >
          {watchlist.map((item) => (
            <View key={item.auction_id} style={styles.watchCard}>
              <View style={styles.watchInfo}>
                <Text style={styles.watchTitle} numberOfLines={1}>
                  {item.title}
                </Text>
                <Text style={styles.watchPrice}>
                  Current: PKR {Number(item.current_bid || item.starting_price).toLocaleString()}
                </Text>
                <Text style={styles.watchTime}>
                  Status: {(item.auction_status || 'ACTIVE').toUpperCase()}
                </Text>
              </View>
              <View style={styles.watchActions}>
                <TouchableOpacity
                  style={styles.actionBtn}
                  onPress={() => onSelectAuction(item.auction_id)}
                >
                  <Text style={styles.actionBtnText}>View Lot</Text>
                </TouchableOpacity>
                <TouchableOpacity
                  style={styles.removeWatchBtn}
                  onPress={() => handleRemove(item.auction_id)}
                >
                  <Ionicons name="trash-outline" size={15} color={Colors.danger} />
                </TouchableOpacity>
              </View>
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
};

// ─── 4. My Bids & Won Auctions View ───────────────────────────
export const BidsScreen: React.FC<{
  onSelectAuction: (id: number) => void;
  onCheckout: (orderId: number) => void;
  onExploreMore?: () => void;
}> = ({ onSelectAuction, onCheckout, onExploreMore }) => {
  const { user } = useAuth();
  const [tab, setTab] = useState<'bids' | 'won'>('bids');
  const [bids, setBids] = useState<any[]>([]);
  const [won, setWon] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const loadBids = async () => {
    if (!user) {
      setLoading(false);
      return;
    }
    try {
      const [bidRes, wonRes] = await Promise.all([
        MobileApi.getMyBids().catch(() => ({ data: [] })),
        MobileApi.getWonAuctions().catch(() => ({ data: [] })),
      ]);
      setBids(bidRes.data || []);
      setWon(wonRes.data || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadBids();
  }, [user]);

  if (!user) {
    return (
      <View style={styles.container}>
        <EmptyState
          iconName="lock-closed-outline"
          title="Sign In Required"
          subtitle="Sign in to track your active bids and won lots."
        />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.tabToggle}>
        <TouchableOpacity
          style={[styles.toggleBtn, tab === 'bids' && styles.toggleActive]}
          onPress={() => setTab('bids')}
        >
          <Text style={[styles.toggleText, tab === 'bids' && styles.toggleTextActive]}>
            Active Bids ({bids.length})
          </Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.toggleBtn, tab === 'won' && styles.toggleActive]}
          onPress={() => setTab('won')}
        >
          <Text style={[styles.toggleText, tab === 'won' && styles.toggleTextActive]}>
            Won Lots ({won.length})
          </Text>
        </TouchableOpacity>
      </View>

      {loading ? (
        <LoadingSkeleton count={2} />
      ) : tab === 'bids' ? (
        bids.length === 0 ? (
          <EmptyState
            iconName="hammer-outline"
            title="No Active Bids"
            subtitle="You haven't placed any active bids yet."
            buttonText={onExploreMore ? "Explore Auctions" : undefined}
            onButtonPress={onExploreMore}
          />
        ) : (
          <ScrollView showsVerticalScrollIndicator={false}>
            {bids.map((b) => (
              <View key={b.bid_id || b.auction_id} style={styles.listItem}>
                <View style={styles.listDetails}>
                  <Text style={styles.listTitle}>{b.auction_title || `Lot #${b.auction_id}`}</Text>
                  <Text style={styles.listPrice}>
                    Your Bid: PKR {Number(b.bid_amount).toLocaleString()}
                  </Text>
                  <View
                    style={[
                      styles.leadBadge,
                      b.is_leading ? styles.leadWinning : styles.leadOutbid,
                      { flexDirection: 'row', alignItems: 'center' }
                    ]}
                  >
                    <Ionicons
                      name={b.is_leading ? 'trophy' : 'alert-circle-outline'}
                      size={12}
                      color={b.is_leading ? Colors.success : Colors.danger}
                      style={{ marginRight: 4 }}
                    />
                    <Text
                      style={{
                        fontSize: 11,
                        fontWeight: '800',
                        color: b.is_leading ? Colors.success : Colors.danger
                      }}
                    >
                      {b.is_leading ? 'Currently Leading' : 'Outbid'}
                    </Text>
                  </View>
                </View>
                <TouchableOpacity
                  style={styles.actionBtn}
                  onPress={() => onSelectAuction(b.auction_id)}
                >
                  <Text style={styles.actionBtnText}>View Lot</Text>
                </TouchableOpacity>
              </View>
            ))}
          </ScrollView>
        )
      ) : won.length === 0 ? (
        <EmptyState
          iconName="trophy-outline"
          title="No Won Auctions"
          subtitle="Auctions you win will appear here ready for Escrow Checkout."
        />
      ) : (
        <ScrollView showsVerticalScrollIndicator={false}>
          {won.map((w) => (
            <View key={w.order_id || w.auction_id} style={styles.listItem}>
              <View style={styles.listDetails}>
                <Text style={styles.listTitle}>
                  {w.auction_title || `Won Lot #${w.auction_id}`}
                </Text>
                <Text style={styles.wonPrice}>
                  Winning Bid: PKR {Number(w.winning_bid || w.total_amount).toLocaleString()}
                </Text>
              </View>
              <TouchableOpacity
                style={styles.checkoutBtn}
                onPress={() => onCheckout(w.order_id || w.auction_id)}
              >
                <Text style={styles.checkoutBtnText}>Checkout →</Text>
              </TouchableOpacity>
            </View>
          ))}
        </ScrollView>
      )}
    </View>
  );
};

// ─── 5. Orders & Tracking View ────────────────────────────────
export const OrdersScreen: React.FC<{
  onSelectOrder: (orderId: number) => void;
}> = ({ onSelectOrder }) => {
  const { user } = useAuth();
  const [orders, setOrders] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'all' | 'pending' | 'shipped' | 'delivered'>('all');
  const [refreshing, setRefreshing] = useState(false);

  const loadOrders = async () => {
    if (!user) {
      setLoading(false);
      return;
    }
    try {
      const res = await MobileApi.getBuyerOrders();
      setOrders(res.data || []);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    loadOrders();
  }, [user]);

  if (!user) {
    return (
      <View style={styles.container}>
        <EmptyState
          iconName="lock-closed-outline"
          title="Sign In Required"
          subtitle="Sign in to view orders, dispatch codes, and release escrow."
        />
      </View>
    );
  }

  const filteredOrders = orders.filter((o) => {
    if (filter === 'pending') return o.payment_status === 'pending';
    if (filter === 'shipped') return o.shipping_status === 'shipped' || o.shipping_status === 'in_transit';
    if (filter === 'delivered') return o.shipping_status === 'delivered' || o.order_status === 'delivered';
    return true;
  });

  return (
    <View style={styles.container}>
      <Text style={styles.screenHeader}>My Purchases & Orders ({orders.length})</Text>

      {/* Filter Tabs */}
      <View style={styles.sortBar}>
        <TouchableOpacity
          style={[styles.sortChip, filter === 'all' && styles.sortChipActive]}
          onPress={() => setFilter('all')}
        >
          <Text style={[styles.sortChipText, filter === 'all' && styles.sortChipTextActive]}>
            All
          </Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.sortChip, filter === 'pending' && styles.sortChipActive]}
          onPress={() => setFilter('pending')}
        >
          <Text style={[styles.sortChipText, filter === 'pending' && styles.sortChipTextActive]}>
            Pending Pay
          </Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.sortChip, filter === 'shipped' && styles.sortChipActive]}
          onPress={() => setFilter('shipped')}
        >
          <Text style={[styles.sortChipText, filter === 'shipped' && styles.sortChipTextActive]}>
            In Transit
          </Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.sortChip, filter === 'delivered' && styles.sortChipActive]}
          onPress={() => setFilter('delivered')}
        >
          <Text style={[styles.sortChipText, filter === 'delivered' && styles.sortChipTextActive]}>
            Delivered
          </Text>
        </TouchableOpacity>
      </View>

      {loading ? (
        <LoadingSkeleton count={2} />
      ) : filteredOrders.length === 0 ? (
        <EmptyState
          iconName="cube-outline"
          title="No Orders Found"
          subtitle="Purchases won and placed will be tracked with real-time courier statuses."
        />
      ) : (
        <ScrollView
          showsVerticalScrollIndicator={false}
          refreshControl={
            <RefreshControl
              refreshing={refreshing}
              onRefresh={() => {
                setRefreshing(true);
                loadOrders();
              }}
              tintColor={Colors.primary}
            />
          }
        >
          {filteredOrders.map((o) => (
            <TouchableOpacity
              key={o.order_id}
              style={styles.orderCard}
              activeOpacity={0.85}
              onPress={() => onSelectOrder(o.order_id)}
            >
              <View style={styles.orderHeader}>
                <Text style={styles.orderNumber}>ORDER #{o.order_id}</Text>
                <Text
                  style={[
                    styles.statusBadge,
                    o.payment_status === 'paid' ? styles.statusPaid : styles.statusPending,
                  ]}
                >
                  {(o.payment_status || 'PENDING').toUpperCase()}
                </Text>
              </View>
              <Text style={styles.orderTitle}>{o.auction_title || 'Auction Lot'}</Text>
              <Text style={styles.orderAmount}>
                Total: PKR {Number(o.total_amount || o.winning_bid).toLocaleString()}
              </Text>
              <View style={{ flexDirection: 'row', alignItems: 'center', marginTop: 5 }}>
                <Ionicons
                  name={o.shipping_status === 'delivered' ? 'checkmark-circle-outline' : 'car-sport-outline'}
                  size={14}
                  color={o.shipping_status === 'delivered' ? Colors.success : Colors.primaryLight}
                  style={{ marginRight: 6 }}
                />
                <Text style={styles.courierInfo}>
                  {o.shipping_status === 'shipped' || o.shipping_status === 'in_transit'
                    ? `In Transit via ${o.courier || 'TCS'} (${o.tracking_number || 'N/A'})`
                    : o.shipping_status === 'delivered'
                      ? 'Delivered'
                      : 'Awaiting Merchant Packing'}
                </Text>
              </View>
              <View style={styles.viewOrderPrompt}>
                <Text style={styles.viewOrderText}>Tap to View Timeline & Tracking →</Text>
              </View>
            </TouchableOpacity>
          ))}
        </ScrollView>
      )}
    </View>
  );
};

// ─── 6. Profile & Account View ────────────────────────────────
export const ProfileScreen: React.FC<{
  onOpenKyc: () => void;
  onOpenAddresses: () => void;
  onOpenSecurity: () => void;
  onOpenReviews: () => void;
}> = ({ onOpenKyc, onOpenAddresses, onOpenSecurity, onOpenReviews }) => {
  const { user, logout, activeRole, setActiveRole } = useAuth();

  if (!user) {
    return (
      <View style={styles.container}>
        <EmptyState
          iconName="person-outline"
          title="Not Signed In"
          subtitle="Sign in to access your wallet, addresses, and NADRA verification."
        />
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} showsVerticalScrollIndicator={false}>
      {/* Profile Card */}
      <View style={styles.profileCard}>
        <View style={styles.avatarLarge}>
          <Text style={styles.avatarLargeText}>
            {user.full_name?.charAt(0).toUpperCase() || 'U'}
          </Text>
        </View>
        <Text style={styles.userName}>{user.full_name}</Text>
        <Text style={styles.userEmail}>{user.email}</Text>
        <View style={styles.badgeRow}>
          <View
            style={[
              styles.kycStatusBadge,
              user.kyc_status === 'approved' ? styles.kycApproved : styles.kycPending,
              { flexDirection: 'row', alignItems: 'center' }
            ]}
          >
            <Ionicons
              name={user.kyc_status === 'approved' ? 'shield-checkmark' : 'alert-circle'}
              size={12}
              color={user.kyc_status === 'approved' ? Colors.success : Colors.warning}
              style={{ marginRight: 4 }}
            />
            <Text
              style={{
                fontSize: 11,
                fontWeight: '800',
                color: user.kyc_status === 'approved' ? Colors.success : Colors.warning
              }}
            >
              {user.kyc_status === 'approved' ? 'NADRA Biometrics Verified' : 'KYC Pending'}
            </Text>
          </View>
        </View>
      </View>

      {/* Menu Actions */}
      <View style={styles.menuCard}>
        <TouchableOpacity style={styles.menuRow} onPress={onOpenKyc} activeOpacity={0.7}>
          <View style={styles.menuIconWrap}>
            <Ionicons name="card-outline" size={19} color={Colors.primaryLight} />
          </View>
          <View style={styles.menuInfo}>
            <Text style={styles.menuLabel}>NADRA CNIC Verification</Text>
            <Text style={styles.menuSub}>Government identity check for safe live auctions</Text>
          </View>
          <Ionicons name="chevron-forward" size={16} color={Colors.textDim} />
        </TouchableOpacity>

        <TouchableOpacity style={styles.menuRow} onPress={onOpenAddresses} activeOpacity={0.7}>
          <View style={styles.menuIconWrap}>
            <Ionicons name="location-outline" size={19} color={Colors.primaryLight} />
          </View>
          <View style={styles.menuInfo}>
            <Text style={styles.menuLabel}>Shipping Destinations</Text>
            <Text style={styles.menuSub}>Manage delivery addresses across Pakistan</Text>
          </View>
          <Ionicons name="chevron-forward" size={16} color={Colors.textDim} />
        </TouchableOpacity>

        <TouchableOpacity style={styles.menuRow} onPress={onOpenSecurity} activeOpacity={0.7}>
          <View style={styles.menuIconWrap}>
            <Ionicons name="shield-checkmark-outline" size={19} color={Colors.primaryLight} />
          </View>
          <View style={styles.menuInfo}>
            <Text style={styles.menuLabel}>Security & 2FA</Text>
            <Text style={styles.menuSub}>Password, authenticator app, and active sessions</Text>
          </View>
          <Ionicons name="chevron-forward" size={16} color={Colors.textDim} />
        </TouchableOpacity>

        <TouchableOpacity style={styles.menuRow} onPress={onOpenReviews} activeOpacity={0.7}>
          <View style={styles.menuIconWrap}>
            <Ionicons name="star-outline" size={19} color={Colors.gold} />
          </View>
          <View style={styles.menuInfo}>
            <Text style={styles.menuLabel}>Merchant Reviews & Ratings</Text>
            <Text style={styles.menuSub}>Feedback and reputation ratings</Text>
          </View>
          <Ionicons name="chevron-forward" size={16} color={Colors.textDim} />
        </TouchableOpacity>

        {user.role === 'seller' && (
          <TouchableOpacity
            style={styles.menuRow}
            onPress={() => setActiveRole(activeRole === 'seller' ? 'buyer' : 'seller')}
            activeOpacity={0.7}
          >
            <View style={styles.menuIconWrap}>
              <Ionicons name="briefcase-outline" size={19} color={Colors.primaryLight} />
            </View>
            <View style={styles.menuInfo}>
              <Text style={styles.menuLabel}>
                Switch to {activeRole === 'seller' ? 'Buyer Mode' : 'Seller Studio'}
              </Text>
              <Text style={styles.menuSub}>Manage inventory, listings, and payouts</Text>
            </View>
            <Ionicons name="swap-horizontal" size={18} color={Colors.primaryLight} />
          </TouchableOpacity>
        )}

        <TouchableOpacity
          style={[styles.menuRow, { borderBottomWidth: 0 }]}
          onPress={logout}
          activeOpacity={0.7}
        >
          <View style={[styles.menuIconWrap, { backgroundColor: 'rgba(239, 68, 68, 0.12)' }]}>
            <Ionicons name="log-out-outline" size={19} color={Colors.danger} />
          </View>
          <View style={styles.menuInfo}>
            <Text style={[styles.menuLabel, { color: Colors.danger }]}>Sign Out</Text>
            <Text style={styles.menuSub}>Safely terminate this mobile session</Text>
          </View>
          <Ionicons name="chevron-forward" size={16} color={Colors.textDim} />
        </TouchableOpacity>
      </View>
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  // ─── Shared ────────────────────────────────────────────────────
  container: {
    flex: 1,
    backgroundColor: Colors.background,
    padding: 16,
  },

  // ─── HomeScreen ────────────────────────────────────────────────
  heroBanner: {
    backgroundColor: '#0E1726',
    borderWidth: 1,
    borderColor: 'rgba(81, 69, 229, 0.4)',
    borderRadius: 18,
    padding: 20,
    marginBottom: 20,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 14,
    elevation: 4,
  },
  heroBadge: {
    backgroundColor: 'rgba(121, 82, 245, 0.15)',
    alignSelf: 'flex-start',
    paddingHorizontal: 9,
    paddingVertical: 4,
    borderRadius: 6,
    marginBottom: 10,
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: 1,
    borderColor: 'rgba(121, 82, 245, 0.3)',
  },
  heroBadgeText: {
    fontSize: 9,
    fontWeight: '900',
    color: Colors.primaryLight,
    letterSpacing: 0.5,
  },
  heroTitle: {
    fontSize: 19,
    fontWeight: '900',
    color: Colors.text,
    lineHeight: 26,
    marginBottom: 6,
  },
  heroSubtitle: {
    fontSize: 11,
    color: Colors.textMuted,
    marginBottom: 16,
    lineHeight: 16,
  },
  heroBtn: {
    backgroundColor: Colors.primary,
    alignSelf: 'flex-start',
    paddingHorizontal: 18,
    paddingVertical: 10,
    borderRadius: 10,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.3,
    shadowRadius: 6,
    elevation: 3,
  },
  heroBtnText: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.textWhite,
    letterSpacing: 0.3,
  },
  sectionHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 12,
  },
  sectionTitle: {
    fontSize: 18,
    fontWeight: '700',
    color: Colors.text,
  },
  sectionLink: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.primaryLight,
  },
  catScroll: {
    marginBottom: 20,
  },
  catBubble: {
    backgroundColor: Colors.surface,
    borderWidth: 1,
    borderColor: Colors.border,
    borderRadius: 14,
    paddingHorizontal: 14,
    paddingVertical: 12,
    marginRight: 10,
    alignItems: 'center',
    minWidth: 72,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 4,
    elevation: 2,
  },
  catIcon: {
    fontSize: 18,
    marginBottom: 4,
  },
  catName: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.text,
  },
  aiCard: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: 'rgba(81, 69, 229, 0.10)',
    borderWidth: 1,
    borderColor: 'rgba(81, 69, 229, 0.3)',
    borderRadius: 14,
    padding: 14,
    marginTop: 10,
    marginBottom: 24,
  },
  aiIcon: {
    fontSize: 24,
    marginRight: 12,
  },
  aiContent: {
    flex: 1,
  },
  aiTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.primaryLight,
  },
  aiSub: {
    fontSize: 11,
    color: Colors.textMuted,
  },
  aiArrow: {
    fontSize: 16,
    color: Colors.primaryLight,
    fontWeight: '800',
  },

  // ─── AuctionsScreen ───────────────────────────────────────────
  searchBar: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: Colors.surface,
    borderWidth: 1,
    borderColor: Colors.border,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 11,
    marginBottom: 12,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.2,
    shadowRadius: 3,
    elevation: 1,
  },
  searchIcon: {
    fontSize: 14,
    marginRight: 8,
  },
  searchInput: {
    flex: 1,
    fontSize: 14,
    color: Colors.text,
  },
  sortBar: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 14,
    flexWrap: 'wrap',
  },
  sortChip: {
    backgroundColor: Colors.surface,
    paddingHorizontal: 12,
    paddingVertical: 7,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  sortChipActive: {
    backgroundColor: Colors.primary,
    borderColor: Colors.primary,
  },
  sortChipText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.textMuted,
  },
  sortChipTextActive: {
    color: '#FFFFFF',
  },

  // ─── WatchlistScreen ──────────────────────────────────────────
  watchCard: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: Colors.card,
    padding: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 10,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 6,
    elevation: 2,
  },
  watchInfo: {
    flex: 1,
    marginRight: 10,
  },
  watchTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 4,
  },
  watchPrice: {
    fontSize: 12,
    color: Colors.primaryLight,
    fontWeight: '700',
    marginBottom: 2,
  },
  watchTime: {
    fontSize: 10,
    color: Colors.textDim,
  },
  watchActions: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  removeWatchBtn: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: Colors.dangerLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  removeWatchText: {
    color: Colors.danger,
    fontSize: 12,
    fontWeight: '800',
  },

  // ─── BidsScreen ───────────────────────────────────────────────
  tabToggle: {
    flexDirection: 'row',
    backgroundColor: Colors.surface,
    borderRadius: 12,
    padding: 4,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  toggleBtn: {
    flex: 1,
    paddingVertical: 9,
    alignItems: 'center',
    borderRadius: 9,
  },
  toggleActive: {
    backgroundColor: Colors.primary,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.35,
    shadowRadius: 4,
    elevation: 2,
  },
  toggleText: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.textMuted,
  },
  toggleTextActive: {
    color: '#FFFFFF',
  },
  listItem: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: Colors.card,
    padding: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 10,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 6,
    elevation: 2,
  },
  listDetails: {
    flex: 1,
    marginRight: 10,
  },
  listTitle: {
    fontSize: 13,
    fontWeight: '700',
    color: Colors.text,
    marginBottom: 4,
  },
  listPrice: {
    fontSize: 12,
    color: Colors.text,
    fontWeight: '600',
  },
  leadBadge: {
    paddingHorizontal: 7,
    paddingVertical: 3,
    borderRadius: 6,
    marginTop: 5,
    alignSelf: 'flex-start',
  },
  leadWinning: {
    backgroundColor: Colors.successLight,
  },
  leadOutbid: {
    backgroundColor: Colors.dangerLight,
  },
  wonPrice: {
    fontSize: 12,
    color: Colors.success,
    fontWeight: '700',
  },
  actionBtn: {
    backgroundColor: 'transparent',
    paddingHorizontal: 14,
    paddingVertical: 9,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: Colors.primary,
  },
  actionBtnText: {
    fontSize: 12,
    color: Colors.text,
    fontWeight: '700',
  },
  checkoutBtn: {
    backgroundColor: Colors.primary,
    paddingHorizontal: 16,
    height: 48,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: Colors.primaryDark,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.25,
    shadowRadius: 4,
    elevation: 3,
  },
  checkoutBtnText: {
    fontSize: 12,
    fontWeight: '800',
    color: '#FFFFFF',
  },

  // ─── OrdersScreen ─────────────────────────────────────────────
  screenHeader: {
    fontSize: 18,
    fontWeight: '700',
    color: Colors.text,
    marginBottom: 14,
  },
  orderCard: {
    backgroundColor: Colors.card,
    padding: 16,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 12,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 8,
    elevation: 2,
  },
  orderHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 6,
  },
  orderNumber: {
    fontSize: 11,
    fontWeight: '800',
    color: Colors.primaryLight,
    letterSpacing: 0.5,
  },
  statusBadge: {
    fontSize: 10,
    fontWeight: '800',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 6,
    overflow: 'hidden',
  },
  statusPaid: {
    backgroundColor: Colors.successLight,
    color: Colors.success,
  },
  statusPending: {
    backgroundColor: Colors.warningLight,
    color: Colors.warning,
  },
  orderTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: Colors.text,
    marginBottom: 4,
  },
  orderAmount: {
    fontSize: 12,
    color: Colors.textMuted,
    marginBottom: 6,
  },
  courierInfo: {
    fontSize: 11,
    color: Colors.primaryLight,
    marginBottom: 8,
  },
  viewOrderPrompt: {
    borderTopWidth: 1,
    borderTopColor: Colors.borderSubtle,
    paddingTop: 8,
  },
  viewOrderText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.primaryLight,
  },

  // ─── ProfileScreen ────────────────────────────────────────────
  profileCard: {
    alignItems: 'center',
    backgroundColor: '#0E1726',
    borderRadius: 20,
    padding: 24,
    borderWidth: 1,
    borderColor: 'rgba(81, 69, 229, 0.3)',
    marginBottom: 16,
    shadowColor: Colors.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 12,
    elevation: 4,
  },
  avatarLarge: {
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: Colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 12,
    borderWidth: 3,
    borderColor: Colors.primaryLight,
  },
  avatarLargeText: {
    fontSize: 28,
    fontWeight: '900',
    color: '#FFFFFF',
  },
  userName: {
    fontSize: 18,
    fontWeight: '800',
    color: '#FFFFFF',
  },
  userEmail: {
    fontSize: 12,
    color: Colors.textMuted,
    marginTop: 2,
    marginBottom: 12,
  },
  badgeRow: {
    flexDirection: 'row',
  },
  kycStatusBadge: {
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 8,
    flexDirection: 'row',
    alignItems: 'center',
  },
  kycApproved: {
    backgroundColor: Colors.successLight,
  },
  kycPending: {
    backgroundColor: Colors.warningLight,
  },
  menuCard: {
    backgroundColor: Colors.card,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 24,
    shadowColor: '#000000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 8,
    elevation: 2,
    overflow: 'hidden',
  },
  menuRow: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 15,
    borderBottomWidth: 1,
    borderBottomColor: Colors.borderSubtle,
  },
  menuIconWrap: {
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: Colors.badgeBg,
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 12,
  },
  menuIcon: {
    fontSize: 20,
    marginRight: 12,
  },
  menuInfo: {
    flex: 1,
  },
  menuLabel: {
    fontSize: 13,
    fontWeight: '700',
    color: Colors.text,
  },
  menuSub: {
    fontSize: 11,
    color: Colors.textMuted,
    marginTop: 1,
  },
  menuArrow: {
    fontSize: 14,
    color: Colors.textDim,
  },
});
