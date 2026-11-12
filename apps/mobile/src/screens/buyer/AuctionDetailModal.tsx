import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  Modal,
  Image,
  ScrollView,
  TouchableOpacity,
  TextInput,
  ActivityIndicator,
  Alert,
} from 'react-native';
import { Colors } from '../../theme/colors';
import { Ionicons } from '@expo/vector-icons';
import { MobileApi, getApiBaseUrl } from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { RealtimeService, RealtimeSubscription } from '../../services/realtimeService';
import { NotificationService } from '../../services/notificationService';

interface AuctionDetailModalProps {
  visible: boolean;
  auctionId: number | null;
  onClose: () => void;
  onBidPlaced?: () => void;
  onWatchlistChanged?: () => void;
}

export const AuctionDetailModal: React.FC<AuctionDetailModalProps> = ({
  visible,
  auctionId,
  onClose,
  onBidPlaced,
  onWatchlistChanged,
}) => {
  const { user } = useAuth();
  const [auction, setAuction] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const [bidding, setBidding] = useState(false);
  const [isWatched, setIsWatched] = useState(false);
  const [watchingLoading, setWatchingLoading] = useState(false);

  // Subtabs: details, history, autobid
  const [activeTab, setActiveTab] = useState<'details' | 'history' | 'autobid'>('details');

  // Input states
  const [bidAmount, setBidAmount] = useState('');
  const [autoBidCeiling, setAutoBidCeiling] = useState('');
  const [activeAutoBid, setActiveAutoBid] = useState<any>(null);

  // Anti-sniping extension message banner
  const [extensionAlert, setExtensionAlert] = useState<string | null>(null);

  // Real-time remaining time countdown in seconds
  const [remainingSeconds, setRemainingSeconds] = useState<number>(0);

  const fetchDetail = async () => {
    if (!auctionId) return;
    try {
      const res = await MobileApi.getAuctionDetail(auctionId);
      const data = res.data || {};
      setAuction(data);
      setIsWatched(!!data.is_watched);

      const minInc = parseFloat(data.min_increment || '100');
      const curr = parseFloat(data.current_bid || data.starting_price || '0');
      setBidAmount(String(curr + minInc));

      if (data.end_time) {
        const diff = Math.max(0, Math.floor((new Date(data.end_time).getTime() - Date.now()) / 1000));
        setRemainingSeconds(diff);
      }
    } catch (err: any) {
      console.warn('Auction load error:', err.message);
    }
  };

  const fetchAutoBidStatus = async () => {
    if (!user || !auctionId) return;
    try {
      const res = await MobileApi.getMyAutoBid(auctionId);
      setActiveAutoBid(res.data || null);
      if (res.data?.max_amount) {
        setAutoBidCeiling(String(res.data.max_amount));
      }
    } catch {
      setActiveAutoBid(null);
    }
  };

  // Setup Real-time SSE subscription & countdown timer
  useEffect(() => {
    if (!visible || !auctionId) return;

    setLoading(true);
    fetchDetail().finally(() => setLoading(false));
    fetchAutoBidStatus();

    // 1. Subscribe to Real-time SSE stream
    const subscription: RealtimeSubscription = RealtimeService.subscribeToAuction(
      getApiBaseUrl(),
      auctionId,
      {
        onBid: (event) => {
          setAuction((prev: any) => {
            if (!prev) return prev;
            return {
              ...prev,
              current_bid: event.bid_amount,
              bid_count: event.bid_count,
            };
          });

          // Suggest next bid increment
          const minInc = parseFloat(auction?.min_increment || '100');
          setBidAmount(String(event.bid_amount + minInc));

          // In-app alert if bidder is not current user
          NotificationService.notify({
            type: 'outbid',
            title: `⚡ Live Bid Placed: PKR ${event.bid_amount.toLocaleString()}`,
            message: `New bid on Lot #${auctionId} by ${event.bidder_name}`,
            auctionId,
          });
        },
        onStatus: (event) => {
          setAuction((prev: any) => (prev ? { ...prev, auction_status: event.status } : prev));
        },
        onTimeExtended: (event) => {
          setExtensionAlert(`Anti-Sniping Protection: End time extended to ${new Date(event.new_end_time).toLocaleTimeString()}!`);
          const diff = Math.max(0, Math.floor((new Date(event.new_end_time).getTime() - Date.now()) / 1000));
          setRemainingSeconds(diff);
        },
      }
    );

    // 2. Local countdown timer
    const countdown = setInterval(() => {
      setRemainingSeconds((prev) => (prev > 0 ? prev - 1 : 0));
    }, 1000);

    return () => {
      subscription.unsubscribe();
      clearInterval(countdown);
    };
  }, [visible, auctionId]);

  const toggleWatchlist = async () => {
    if (!user) {
      Alert.alert('Sign In Required', 'Please sign in to save items to your watchlist.');
      return;
    }
    setWatchingLoading(true);
    try {
      if (isWatched) {
        await MobileApi.removeFromWatchlist(auctionId!);
        setIsWatched(false);
      } else {
        await MobileApi.addToWatchlist(auctionId!);
        setIsWatched(true);
      }
      if (onWatchlistChanged) onWatchlistChanged();
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Could not update watchlist');
    } finally {
      setWatchingLoading(false);
    }
  };

  const handlePlaceBid = async () => {
    if (!user) {
      Alert.alert('Sign In Required', 'Please sign in to participate in live bidding.');
      return;
    }
    const val = parseFloat(bidAmount);
    const currPrice = parseFloat(auction?.current_bid || auction?.starting_price || '0');
    const minInc = parseFloat(auction?.min_increment || '100');

    if (isNaN(val) || val < currPrice + minInc) {
      Alert.alert('Invalid Bid', `Minimum allowable bid is PKR ${(currPrice + minInc).toLocaleString()}.`);
      return;
    }

    setBidding(true);
    try {
      await MobileApi.placeBid(auctionId!, val);
      NotificationService.triggerWinning(auction?.title || `Lot #${auctionId}`, val, auctionId!);
      Alert.alert('Bid Submitted! 🔨', `Your bid of PKR ${val.toLocaleString()} has been placed.`);
      fetchDetail();
      if (onBidPlaced) onBidPlaced();
    } catch (err: any) {
      Alert.alert('Bidding Error', err.message || 'Failed to place bid');
    } finally {
      setBidding(false);
    }
  };

  const handleSetAutoBid = async () => {
    if (!user) {
      Alert.alert('Sign In Required', 'Please sign in to configure auto-bidding.');
      return;
    }
    const maxVal = parseFloat(autoBidCeiling);
    const currPrice = parseFloat(auction?.current_bid || auction?.starting_price || '0');

    if (isNaN(maxVal) || maxVal <= currPrice) {
      Alert.alert('Invalid Ceiling', 'Auto-bid ceiling must exceed the current highest bid.');
      return;
    }

    setBidding(true);
    try {
      await MobileApi.setAutoBid(auctionId!, maxVal);
      Alert.alert('Proxy Auto-Bid Activated 🤖', `NexusBids will bid incrementally on your behalf up to PKR ${maxVal.toLocaleString()}.`);
      fetchAutoBidStatus();
      fetchDetail();
    } catch (err: any) {
      Alert.alert('Auto-Bid Error', err.message || 'Could not configure auto-bid');
    } finally {
      setBidding(false);
    }
  };

  const handleCancelAutoBid = async () => {
    setBidding(true);
    try {
      await MobileApi.cancelAutoBid(auctionId!);
      Alert.alert('Cancelled', 'Proxy auto-bid has been deactivated.');
      setActiveAutoBid(null);
      setAutoBidCeiling('');
    } catch (err: any) {
      Alert.alert('Error', err.message || 'Could not cancel auto-bid');
    } finally {
      setBidding(false);
    }
  };

  const addQuickIncrement = (inc: number) => {
    const current = parseFloat(bidAmount) || parseFloat(auction?.current_bid || auction?.starting_price || '0');
    setBidAmount(String(current + inc));
  };

  const formatCountdown = (totalSec: number) => {
    if (totalSec <= 0) return 'Ended';
    const d = Math.floor(totalSec / 86400);
    const h = Math.floor((totalSec % 86400) / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    if (d > 0) return `${d}d ${h}h ${m}m ${s}s`;
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  };

  if (!visible) return null;

  const currentPrice = auction ? parseFloat(auction.current_bid || auction.starting_price || 0) : 0;
  const minInc = auction ? parseFloat(auction.min_increment || 100) : 100;
  const bids = auction?.bids || [];

  return (
    <Modal visible={visible} animationType="slide" transparent>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          {/* Header Bar */}
          <View style={styles.topBar}>
            <View style={styles.lotBadge}>
              <Text style={styles.lotText}>LOT #{auctionId}</Text>
            </View>

            <View style={styles.headerRight}>
              <TouchableOpacity
                style={styles.watchBtn}
                onPress={toggleWatchlist}
                disabled={watchingLoading}
              >
                <Ionicons
                  name={isWatched ? 'heart' : 'heart-outline'}
                  size={18}
                  color={isWatched ? Colors.danger : Colors.textMuted}
                />
              </TouchableOpacity>
              <TouchableOpacity onPress={onClose} style={styles.closeBtn}>
                <Ionicons name="close" size={18} color={Colors.textMuted} />
              </TouchableOpacity>
            </View>
          </View>

          {extensionAlert && (
            <View style={styles.extensionBanner}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                <Ionicons name="time-outline" size={15} color={Colors.warning} />
                <Text style={styles.extensionText}>{extensionAlert}</Text>
              </View>
            </View>
          )}

          {loading && !auction ? (
            <View style={styles.loaderBox}>
              <ActivityIndicator size="large" color={Colors.primary} />
            </View>
          ) : (
            <ScrollView showsVerticalScrollIndicator={false}>
              {/* Product Image */}
              <Image
                source={{
                  uri: auction?.image || 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600',
                }}
                style={styles.image}
              />

              <View style={styles.body}>
                <Text style={styles.title}>{auction?.title}</Text>

                {/* Live Countdown & Status Ribbon */}
                <View style={styles.ribbonRow}>
                  <View style={styles.countdownBadge}>
                    <Ionicons
                      name="time-outline"
                      size={13}
                      color={remainingSeconds <= 300 ? Colors.danger : Colors.warning}
                      style={{ marginRight: 4 }}
                    />
                    <Text style={styles.countdownText}>
                      Time Left: {formatCountdown(remainingSeconds)}
                    </Text>
                  </View>
                  <View style={styles.statusBadge}>
                    <Text style={styles.statusText}>
                      {(auction?.auction_status || 'ACTIVE').toUpperCase()}
                    </Text>
                  </View>
                </View>

                {/* Price Summary Card */}
                <View style={styles.priceCard}>
                  <View>
                    <Text style={styles.priceLabel}>Current Highest Bid</Text>
                    <Text style={styles.priceAmount}>
                      PKR {currentPrice.toLocaleString()}
                    </Text>
                    <Text style={styles.minIncText}>
                      Min. next bid: PKR {(currentPrice + minInc).toLocaleString()} (+PKR {minInc})
                    </Text>
                  </View>
                  <View style={styles.bidCountBadge}>
                    <Text style={styles.bidCountNum}>{auction?.bid_count || 0}</Text>
                    <Text style={styles.bidCountLabel}>BIDS</Text>
                  </View>
                </View>

                {/* Segmented Subtab Switcher */}
                <View style={styles.subtabBar}>
                  <TouchableOpacity
                    style={[styles.subtab, activeTab === 'details' && styles.subtabActive]}
                    onPress={() => setActiveTab('details')}
                  >
                    <Text style={[styles.subtabText, activeTab === 'details' && styles.subtabTextActive]}>
                      Bid & Details
                    </Text>
                  </TouchableOpacity>
                  <TouchableOpacity
                    style={[styles.subtab, activeTab === 'autobid' && styles.subtabActive]}
                    onPress={() => setActiveTab('autobid')}
                  >
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
                      <Ionicons
                        name="hardware-chip-outline"
                        size={13}
                        color={activeTab === 'autobid' ? Colors.primaryLight : Colors.textMuted}
                      />
                      <Text style={[styles.subtabText, activeTab === 'autobid' && styles.subtabTextActive]}>
                        Auto-Bid
                      </Text>
                    </View>
                  </TouchableOpacity>
                  <TouchableOpacity
                    style={[styles.subtab, activeTab === 'history' && styles.subtabActive]}
                    onPress={() => setActiveTab('history')}
                  >
                    <Text style={[styles.subtabText, activeTab === 'history' && styles.subtabTextActive]}>
                      Bids ({bids.length})
                    </Text>
                  </TouchableOpacity>
                </View>

                {/* SUBTAB 1: BID & DETAILS */}
                {activeTab === 'details' && (
                  <>
                    {/* Bidding Control Panel */}
                    <View style={styles.biddingBox}>
                      <Text style={styles.biddingBoxTitle}>Place Real-Time Bid</Text>

                      {/* Quick Increment Chips */}
                      <View style={styles.chipsRow}>
                        <TouchableOpacity style={styles.chip} onPress={() => addQuickIncrement(500)}>
                          <Text style={styles.chipText}>+PKR 500</Text>
                        </TouchableOpacity>
                        <TouchableOpacity style={styles.chip} onPress={() => addQuickIncrement(1000)}>
                          <Text style={styles.chipText}>+PKR 1,000</Text>
                        </TouchableOpacity>
                        <TouchableOpacity style={styles.chip} onPress={() => addQuickIncrement(5000)}>
                          <Text style={styles.chipText}>+PKR 5,000</Text>
                        </TouchableOpacity>
                      </View>

                      {/* Bid Input & Submit Button */}
                      <View style={styles.bidInputRow}>
                        <TextInput
                          style={styles.bidInput}
                          placeholder="Your bid amount"
                          placeholderTextColor={Colors.textDim}
                          keyboardType="numeric"
                          value={bidAmount}
                          onChangeText={setBidAmount}
                        />
                        <TouchableOpacity
                          style={styles.bidSubmitBtn}
                          onPress={handlePlaceBid}
                          disabled={bidding}
                        >
                          {bidding ? (
                            <ActivityIndicator color="#ffffff" />
                          ) : (
                            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                              <Ionicons name="hammer" size={14} color="#ffffff" />
                              <Text style={styles.bidSubmitBtnText}>Place Bid</Text>
                            </View>
                          )}
                        </TouchableOpacity>
                      </View>
                    </View>

                    {/* Lot Specifications & Description */}
                    <View style={styles.specCard}>
                      <Text style={styles.sectionTitle}>Lot Specifications</Text>
                      <View style={styles.specRow}>
                        <Text style={styles.specKey}>Category</Text>
                        <Text style={styles.specVal}>{auction?.category_name || 'Premium Collectible'}</Text>
                      </View>
                      <View style={styles.specRow}>
                        <Text style={styles.specKey}>Starting Price</Text>
                        <Text style={styles.specVal}>PKR {Number(auction?.starting_price || 0).toLocaleString()}</Text>
                      </View>
                      <View style={styles.specRow}>
                        <Text style={styles.specKey}>Escrow Protection</Text>
                        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
                          <Ionicons name="shield-checkmark" size={13} color={Colors.success} />
                          <Text style={[styles.specVal, { color: Colors.success }]}>100% Escrow Guaranteed</Text>
                        </View>
                      </View>
                      <View style={styles.specRow}>
                        <Text style={styles.specKey}>Seller Identity</Text>
                        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
                          <Ionicons name="checkmark-done-circle" size={13} color={Colors.primaryLight} />
                          <Text style={styles.specVal}>NADRA Verified Merchant</Text>
                        </View>
                      </View>
                    </View>

                    <View style={styles.descCard}>
                      <Text style={styles.sectionTitle}>Item Description</Text>
                      <Text style={styles.descText}>
                        {auction?.description || 'Authentic lot verified by NexusBids inspection panel.'}
                      </Text>
                    </View>
                  </>
                )}

                {/* SUBTAB 2: AUTO-BID */}
                {activeTab === 'autobid' && (
                  <View style={styles.autoBidCard}>
                    <Text style={styles.autoBidTitle}>Proxy Auto-Bidding Assistant</Text>
                    <Text style={styles.autoBidDesc}>
                      Set the maximum ceiling you are willing to pay. NexusBids will automatically bid on your behalf by minimal increments whenever you are outbid.
                    </Text>

                    {activeAutoBid ? (
                      <View style={styles.activeAutoBadge}>
                        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
                          <Ionicons name="checkmark-circle" size={14} color={Colors.success} />
                          <Text style={styles.activeAutoText}>
                            Auto-bid active up to PKR {Number(activeAutoBid.max_amount).toLocaleString()}
                          </Text>
                        </View>
                        <TouchableOpacity style={styles.cancelAutoBtn} onPress={handleCancelAutoBid}>
                          <Text style={styles.cancelAutoBtnText}>Deactivate</Text>
                        </TouchableOpacity>
                      </View>
                    ) : null}

                    <Text style={styles.label}>Maximum Auto-Bid Ceiling (PKR)</Text>
                    <TextInput
                      style={styles.input}
                      placeholder="e.g. 50000"
                      placeholderTextColor={Colors.textDim}
                      keyboardType="numeric"
                      value={autoBidCeiling}
                      onChangeText={setAutoBidCeiling}
                    />

                    <TouchableOpacity
                      style={styles.autoBidBtn}
                      onPress={handleSetAutoBid}
                      disabled={bidding}
                    >
                      {bidding ? (
                        <ActivityIndicator color="#ffffff" />
                      ) : (
                        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                          <Ionicons name="hardware-chip-outline" size={16} color="#ffffff" />
                          <Text style={styles.autoBidBtnText}>Activate Proxy Auto-Bid</Text>
                        </View>
                      )}
                    </TouchableOpacity>
                  </View>
                )}

                {/* SUBTAB 3: BIDS HISTORY */}
                {activeTab === 'history' && (
                  <View style={styles.historyCard}>
                    <Text style={styles.sectionTitle}>Bidding Ledger ({bids.length} bids)</Text>
                    {bids.length === 0 ? (
                      <Text style={styles.noBidsText}>No bids placed yet. Be the first to bid!</Text>
                    ) : (
                      bids.map((b: any, idx: number) => (
                        <View key={idx} style={styles.bidRow}>
                          <View>
                            <Text style={styles.bidderName}>
                              {b.bidder_name || `Bidder #${b.bidder_id || idx + 1}`}
                              {idx === 0 && <Text style={styles.highBadge}> (HIGH)</Text>}
                            </Text>
                            <Text style={styles.bidTime}>
                              {new Date(b.bid_time || Date.now()).toLocaleTimeString()}
                            </Text>
                          </View>
                          <Text style={styles.bidRowAmount}>
                            PKR {Number(b.bid_amount).toLocaleString()}
                          </Text>
                        </View>
                      ))
                    )}
                  </View>
                )}
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
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: Colors.border,
    backgroundColor: Colors.card,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
  },
  lotBadge: {
    backgroundColor: Colors.badgeBg,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  lotText: {
    fontSize: 11,
    fontWeight: '800',
    color: Colors.primary,
  },
  headerRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  watchBtn: {
    width: 34,
    height: 34,
    borderRadius: 17,
    backgroundColor: Colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: Colors.border,
  },
  watchIcon: {
    fontSize: 16,
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
  extensionBanner: {
    backgroundColor: Colors.warningLight,
    paddingVertical: 8,
    paddingHorizontal: 14,
    borderBottomWidth: 1,
    borderBottomColor: Colors.warning,
  },
  extensionText: {
    color: Colors.warning,
    fontSize: 11,
    fontWeight: '800',
    textAlign: 'center',
  },
  loaderBox: {
    padding: 60,
    alignItems: 'center',
  },
  image: {
    width: '100%',
    height: 250,
    resizeMode: 'cover',
    backgroundColor: Colors.surface,
  },
  body: {
    padding: 16,
    paddingBottom: 40,
  },
  title: {
    fontSize: 19,
    fontWeight: '800',
    color: Colors.text,
    lineHeight: 25,
    marginBottom: 10,
  },
  ribbonRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 14,
  },
  countdownBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: Colors.dangerLight,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: 'rgba(184, 68, 68, 0.25)',
  },
  countdownIcon: {
    fontSize: 12,
    marginRight: 6,
  },
  countdownText: {
    fontSize: 12,
    fontWeight: '800',
    color: Colors.danger,
  },
  statusBadge: {
    backgroundColor: Colors.successLight,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: 'rgba(61, 122, 74, 0.25)',
  },
  statusText: {
    fontSize: 11,
    fontWeight: '800',
    color: Colors.success,
  },
  priceCard: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
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
  priceLabel: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.textDim,
    textTransform: 'uppercase',
  },
  priceAmount: {
    fontSize: 22,
    fontWeight: '900',
    color: Colors.text,
    marginVertical: 2,
  },
  minIncText: {
    fontSize: 11,
    color: Colors.primary,
    fontWeight: '600',
  },
  bidCountBadge: {
    backgroundColor: Colors.badgeBg,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 10,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: Colors.border,
  },
  bidCountNum: {
    fontSize: 18,
    fontWeight: '900',
    color: Colors.primary,
  },
  bidCountLabel: {
    fontSize: 9,
    fontWeight: '800',
    color: Colors.textDim,
  },
  subtabBar: {
    flexDirection: 'row',
    backgroundColor: Colors.surface,
    borderRadius: 12,
    padding: 4,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  subtab: {
    flex: 1,
    paddingVertical: 9,
    alignItems: 'center',
    borderRadius: 8,
  },
  subtabActive: {
    backgroundColor: Colors.primary,
    shadowColor: Colors.primaryDark,
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.2,
    shadowRadius: 3,
    elevation: 2,
  },
  subtabText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.textMuted,
  },
  subtabTextActive: {
    color: '#ffffff',
  },
  biddingBox: {
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
  biddingBoxTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 10,
  },
  chipsRow: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 12,
  },
  chip: {
    flex: 1,
    backgroundColor: Colors.surface,
    paddingVertical: 8,
    borderRadius: 8,
    alignItems: 'center',
    borderWidth: 1,
    borderColor: Colors.border,
  },
  chipText: {
    fontSize: 11,
    fontWeight: '800',
    color: Colors.primary,
  },
  bidInputRow: {
    flexDirection: 'row',
    gap: 10,
  },
  bidInput: {
    flex: 1,
    backgroundColor: Colors.card,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: Colors.border,
    paddingHorizontal: 14,
    fontSize: 14,
    color: Colors.text,
    fontWeight: '700',
  },
  bidSubmitBtn: {
    backgroundColor: Colors.primary,
    height: 50,
    paddingHorizontal: 20,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: Colors.primaryDark,
    shadowOffset: { width: 0, height: 3 },
    shadowOpacity: 0.25,
    shadowRadius: 6,
    elevation: 3,
  },
  bidSubmitBtnText: {
    fontSize: 14,
    fontWeight: '700',
    color: '#ffffff',
  },
  specCard: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 14,
    borderWidth: 1,
    borderColor: Colors.border,
    marginBottom: 12,
  },
  sectionTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 10,
  },
  specRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: Colors.borderSubtle,
  },
  specKey: {
    fontSize: 12,
    color: Colors.textMuted,
  },
  specVal: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.text,
  },
  descCard: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 14,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  descText: {
    fontSize: 12.5,
    color: Colors.textBody,
    lineHeight: 19,
  },
  autoBidCard: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 16,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  autoBidTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: Colors.text,
    marginBottom: 6,
  },
  autoBidDesc: {
    fontSize: 11.5,
    color: Colors.textMuted,
    lineHeight: 17,
    marginBottom: 14,
  },
  activeAutoBadge: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: Colors.successLight,
    padding: 10,
    borderRadius: 10,
    marginBottom: 14,
    borderWidth: 1,
    borderColor: 'rgba(61, 122, 74, 0.25)',
  },
  activeAutoText: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.success,
  },
  cancelAutoBtn: {
    backgroundColor: Colors.dangerLight,
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 6,
  },
  cancelAutoBtnText: {
    fontSize: 10,
    fontWeight: '700',
    color: Colors.danger,
  },
  label: {
    fontSize: 11,
    fontWeight: '700',
    color: Colors.textDim,
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
  autoBidBtn: {
    backgroundColor: Colors.primary,
    height: 50,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: Colors.primaryDark,
    shadowOffset: { width: 0, height: 3 },
    shadowOpacity: 0.25,
    shadowRadius: 6,
    elevation: 3,
  },
  autoBidBtnText: {
    fontSize: 14,
    fontWeight: '700',
    color: '#ffffff',
  },
  historyCard: {
    backgroundColor: Colors.card,
    borderRadius: 14,
    padding: 14,
    borderWidth: 1,
    borderColor: Colors.border,
  },
  noBidsText: {
    fontSize: 12,
    color: Colors.textMuted,
    textAlign: 'center',
    paddingVertical: 20,
  },
  bidRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: Colors.borderSubtle,
  },
  bidderName: {
    fontSize: 12,
    fontWeight: '700',
    color: Colors.text,
  },
  highBadge: {
    color: Colors.gold,
    fontWeight: '800',
  },
  bidTime: {
    fontSize: 10,
    color: Colors.textDim,
  },
  bidRowAmount: {
    fontSize: 13,
    fontWeight: '800',
    color: Colors.primary,
  },
});
